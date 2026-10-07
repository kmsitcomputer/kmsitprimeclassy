{{-- IMP-002 dynamic invoice PDF — commercial invoice, NOT Print Resi.
     Financial truth derives from PaymentSummaryService + Order snapshots only. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        .header { width: 100%; border-bottom: 2px solid #7c3aed; padding-bottom: 8px; margin-bottom: 12px; }
        .header .company { font-size: 18px; font-weight: bold; color: #7c3aed; }
        .header .title { font-size: 14px; float: right; }
        table.items { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.items th, table.items td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        table.items th { background: #f4f0ff; }
        .totals { width: 40%; float: right; }
        .totals td { padding: 2px 6px; }
        .totals .grand { font-weight: bold; border-top: 1px solid #999; }
        .info { margin: 6px 0; }
        .footer { margin-top: 30px; font-size: 10px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
        .left { width: 50%; float: left; }
        .clear { clear: both; }
    </style>
</head>
<body>
    <div class="header">
        <span class="company">{{ $config['company_name'] }}</span>
        <span class="title">{{ $config['title'] }}</span>
        @if(!empty($logo_url)) <img src="{{ $logo_url }}" alt="logo" style="max-height:28px; float:right; margin-left:8px;"> @endif
        <div class="clear"></div>
    </div>

    <div class="info">
        <strong>{{ $order->order_no }}</strong> — {{ $order->created_at?->format('d-m-Y H:i') }}<br>
        @if($config['address']) {{ $config['address'] }}<br>@endif
        @if($config['contact']) {{ $config['contact'] }}<br>@endif
    </div>

    {{-- A1-22: the recipient block honors show_konsumen. Hiding is always
         privacy-safe; a consumer-facing PDF is still constrained to their own
         identity, and internal referral identity is never rendered (A1-13). --}}
    @if($showRecipient)
    <div class="left">
        <strong>Konsumen</strong><br>
        {{ $order->recipient_name_snapshot }}<br>
        {{ $order->recipient_phone_snapshot }}<br>
        {{ $order->address_snapshot }}<br>
        @if($order->village_snapshot) {{ $order->village_snapshot }},@endif
        @if($order->district_snapshot) {{ $order->district_snapshot }},@endif
        @if($order->regency_snapshot) {{ $order->regency_snapshot }},@endif
        @if($order->province_snapshot) {{ $order->province_snapshot }}@endif
    </div>
    @endif

    <div class="left">
        {{-- A1-13: internal referral identity is NEVER shown on a consumer-facing
             invoice, even when branch/global show_sales/show_korsal flags are on.
             Staff (non-owner) viewers retain the permitted content. --}}
        @if($showInternalReferral && $config['show_sales'] && $order->sales) <strong>Sales</strong><br>{{ $order->sales->name }}<br>@endif
        @if($showInternalReferral && $config['show_korsal'] && $order->korsal) <strong>Korsal</strong><br>{{ $order->korsal->name }}<br>@endif
    </div>
    <div class="clear"></div>

    @if($config['show_items'])
    <table class="items">
        <thead>
            <tr>
                <th>Produk</th>
                <th>Variasi</th>
                <th>Qty</th>
                <th>Harga Satuan</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $item->product_name_snapshot }}</td>
                <td>{{ $item->variation_label_snapshot }}</td>
                <td>{{ $item->fulfilled_quantity }}</td>
                <td>Rp {{ number_format((float) $item->unit_price_snapshot, 0, ',', '.') }}</td>
                <td>Rp {{ number_format((float) $item->unit_price_snapshot * $item->fulfilled_quantity, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($config['show_payment_summary'])
    <table class="totals">
        <tr><td>Subtotal</td><td>Rp {{ number_format((float) $order->subtotal_amount, 0, ',', '.') }}</td></tr>
        @if($order->effectiveDiscountAmount() > 0)
        <tr><td>Diskon/Voucher</td><td>- Rp {{ number_format($order->effectiveDiscountAmount(), 0, ',', '.') }}</td></tr>
        @endif
        <tr><td>Ongkir</td><td>Rp {{ number_format((float) $order->shipping_fee_amount, 0, ',', '.') }}</td></tr>
        <tr><td>Biaya Admin</td><td>Rp {{ number_format((float) $order->admin_fee_amount, 0, ',', '.') }}</td></tr>
        <tr class="grand"><td>Total</td><td>Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td></tr>
        @if((float) $order->dp_amount > 0)
        <tr><td>DP</td><td>Rp {{ number_format((float) $summary['verified_dp'], 0, ',', '.') }}</td></tr>
        @endif
        <tr><td>Dibayar</td><td>Rp {{ number_format((float) $summary['total_paid'], 0, ',', '.') }}</td></tr>
        @if((float) $order->remaining_amount > 0)
        <tr><td>Sisa Tagihan</td><td>Rp {{ number_format((float) $order->remaining_amount, 0, ',', '.') }}</td></tr>
        @endif
    </table>
    @endif
    <div class="clear"></div>

    @if($config['show_voucher'] && $order->voucher_id)
    <div class="info">Voucher diterapkan: diskon Rp {{ number_format($order->effectiveDiscountAmount(), 0, ',', '.') }}</div>
    @endif

    @if($config['notes'])
    <div class="info">{{ $config['notes'] }}</div>
    @endif

    <div class="footer">{{ $config['footer'] }}</div>
</body>
</html>