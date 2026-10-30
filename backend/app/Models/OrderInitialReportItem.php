<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInitialReportItem extends Model
{
    protected $table = 'order_initial_report_items';

    protected $fillable = [
        'order_id', 'order_item_id', 'order_no', 'order_created_at',
        'agent_id', 'korsal_id', 'sales_id', 'konsumen_id',
        'product_id', 'product_variation_id', 'sku_snapshot',
        'product_name_snapshot', 'variation_label_snapshot', 'category_name_snapshot',
        'initial_quantity', 'unit_price_snapshot', 'item_subtotal_snapshot',
        'recipient_name_snapshot', 'recipient_phone_snapshot', 'address_snapshot',
        'village_snapshot', 'district_snapshot', 'regency_snapshot', 'province_snapshot',
        'invoice_subtotal_snapshot', 'invoice_discount_snapshot',
        'invoice_shipping_snapshot', 'invoice_admin_fee_snapshot',
        'invoice_total_snapshot', 'initial_paid_snapshot',
        'initial_remaining_snapshot', 'initial_payment_status',
        'payment_method_code_snapshot', 'payment_method_name_snapshot',
        'item_position',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'agent_id' => 'integer',
            'korsal_id' => 'integer',
            'sales_id' => 'integer',
            'konsumen_id' => 'integer',
            'product_id' => 'integer',
            'product_variation_id' => 'integer',
            'initial_quantity' => 'integer',
            'item_position' => 'integer',
            'order_created_at' => 'datetime',
            'unit_price_snapshot' => 'decimal:2',
            'item_subtotal_snapshot' => 'decimal:2',
            'invoice_subtotal_snapshot' => 'decimal:2',
            'invoice_discount_snapshot' => 'decimal:2',
            'invoice_shipping_snapshot' => 'decimal:2',
            'invoice_admin_fee_snapshot' => 'decimal:2',
            'invoice_total_snapshot' => 'decimal:2',
            'initial_paid_snapshot' => 'decimal:2',
            'initial_remaining_snapshot' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
