<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Payment\PaymentSummaryService;
use DateTimeImmutable;

class DeliveryGroupInvoiceService
{
    public function forDate(Order $order, string $deliveryDate): array
    {
        $this->assertDate($deliveryDate);
        $order->loadMissing(['items.shipment.courier', 'items.shipment.selfDeliveredBy', 'paymentMethod']);

        $activeItems = $order->items->filter(fn ($item) => $item->status !== 'dibatalkan' && (int) $item->fulfilled_quantity > 0);
        $items = $activeItems->filter(fn ($item) => $item->requested_delivery_date?->toDateString() === $deliveryDate)
            ->sortBy('id')->values();
        abort_if($items->isEmpty(), 404);

        $shipments = $items->map(fn ($item) => $item->shipment)->filter()->unique('id')->values();
        abort_if($shipments->isEmpty(), 422, 'Delivery group has no canonical shipment.');

        $feeCarriers = $shipments->filter(fn (Shipment $shipment) => $this->toMinorUnits($shipment->shipping_fee_snapshot) > 0)->values();
        if ($feeCarriers->count() > 1) {
            throw new ApiException(__('messages.fulfillment.conflicting_shipping_fee_snapshots'), 422);
        }
        $shippingFeeMinor = $feeCarriers->isEmpty() ? 0 : $this->toMinorUnits($feeCarriers->first()->shipping_fee_snapshot);

        $lineItems = $items->map(function ($item): array {
            $unitPriceMinor = $this->toMinorUnits($item->unit_price_snapshot);

            return [
                'product_name' => $item->product_name_snapshot,
                'variation_label' => $item->variation_label_snapshot,
                'sku' => $item->sku_snapshot,
                'quantity' => (int) $item->fulfilled_quantity,
                'unit_price' => $this->formatMinor($unitPriceMinor),
                'subtotal' => $this->formatMinor($unitPriceMinor * (int) $item->fulfilled_quantity),
                '_subtotal_minor' => $unitPriceMinor * (int) $item->fulfilled_quantity,
            ];
        });
        $itemSubtotalMinor = (int) $lineItems->sum('_subtotal_minor');
        $earliestDate = $activeItems->pluck('requested_delivery_date')->filter()
            ->map(fn ($date) => $date->toDateString())->sort()->first();
        $isEarliestGroup = $earliestDate === $deliveryDate;
        $paymentSummary = PaymentSummaryService::summarize($order);
        $verifiedDpMinor = $this->toMinorUnits((string) $paymentSummary['verified_dp']);

        $deliveryMethods = $shipments->map(fn (Shipment $shipment) => $this->deliveryMethod($shipment))->filter()->unique()->values();
        $courierNames = $shipments->map(fn (Shipment $shipment) => $this->courierName($shipment))->filter()->unique()->values();
        $trackingNumbers = $shipments->pluck('tracking_number')->filter()->unique()->values();

        return [
            'invoice_number' => $order->order_no.'-'.$deliveryDate,
            'delivery_date' => $deliveryDate,
            'order' => $order,
            'items' => $lineItems->map(fn (array $item) => collect($item)->except('_subtotal_minor')->all())->all(),
            'total_item_count' => (int) $items->sum('fulfilled_quantity'),
            'item_subtotal' => $this->formatMinor($itemSubtotalMinor),
            'shipping_fee' => $this->formatMinor($shippingFeeMinor),
            'group_total' => $this->formatMinor($itemSubtotalMinor + $shippingFeeMinor),
            'delivery_methods' => $deliveryMethods->all(),
            'courier_names' => $courierNames->all(),
            'tracking_numbers' => $trackingNumbers->all(),
            'payment' => [
                'method' => $order->paymentMethod?->name ?? $order->paymentMethod?->code,
                'status' => $paymentSummary['payment_status'],
                'has_down_payment' => $this->toMinorUnits($order->dp_amount) > 0,
                'is_earliest_group' => $isEarliestGroup,
                'order_remaining' => $this->formatMinor($this->toMinorUnits($order->remaining_amount)),
                'requested_dp' => $isEarliestGroup && $this->toMinorUnits($order->dp_amount) > 0
                    ? $this->formatMinor($this->toMinorUnits($order->dp_amount)) : null,
                'verified_dp_credit' => $isEarliestGroup && $verifiedDpMinor > 0 ? $this->formatMinor($verifiedDpMinor) : null,
                'dp_credit_date' => $earliestDate,
                'admin_fee_order_level' => $this->formatMinor($this->toMinorUnits($order->admin_fee_amount)),
                'discount_order_level' => $this->formatMinor($this->toMinorUnits($order->discount_amount)),
            ],
        ];
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        abort_if(
            ! $parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date,
            404,
        );
    }

    private function deliveryMethod(Shipment $shipment): ?string
    {
        $meta = is_array($shipment->provider_meta) ? $shipment->provider_meta : [];

        return match ($shipment->shipping_provider_code) {
            'openroute' => 'Kurir Online',
            'free' => 'Gratis',
            'pickup' => 'Pickup',
            'rajaongkir' => trim(strtoupper((string) ($meta['courier'] ?? '')).' '.strtoupper((string) ($meta['service'] ?? ''))) ?: 'Ekspedisi',
            default => $shipment->shipping_provider_code ?: 'Metode pengiriman tidak diketahui',
        };
    }

    private function courierName(Shipment $shipment): ?string
    {
        if ($shipment->shipping_provider_code !== 'openroute') {
            return null;
        }

        return $shipment->courier?->name
            ?? ($shipment->isSelfDelivery() ? $shipment->selfDeliveredBy?->name : null);
    }

    private function toMinorUnits(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new ApiException(__('messages.fulfillment.invalid_monetary_amount'), 422);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinor(int $amount): string
    {
        return number_format(intdiv($amount, 100), 0, ',', '.').','.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}