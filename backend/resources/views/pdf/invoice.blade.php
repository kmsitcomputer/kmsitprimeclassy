{{-- IMP-002 dynamic invoice PDF — commercial invoice, NOT Print Resi.
     Financial truth derives from PaymentSummaryService + Order snapshots only.
     Layout remediation: PRESENTATION ONLY — no calculation lives in this template; every amount is read
     from the canonical order / payment summary exactly as before. DomPDF-safe CSS (tables, no flex). --}}
@php
    $money = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $payStatus = $summary['payment_status'] ?? $order->payment_status;
    $statusLabel = match (true) {
        $payStatus === 'paid' => 'LUNAS',
        (float) ($summary['total_paid'] ?? 0) > 0 => 'Dibayar Sebagian',
        $payStatus === 'pending_verification' => 'Menunggu Verifikasi',
        default => 'Belum Dibayar',
    };
    $statusTone = $statusLabel === 'LUNAS' ? 'ok' : ($statusLabel === 'Dibayar Sebagian' ? 'part' : 'due');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_no }}</title>
    <style>
        @page { margin: 28px 34px 64px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f1a1b; line-height: 1.35; }
        table { border-collapse: collapse; }
        .w100 { width: 100%; }
        .muted { color: #6b5f61; }
        .right { text-align: right; }
        .center { text-align: center; }

        /* header */
        .header { border-bottom: 2px solid #8B2635; padding-bottom: 10px; margin-bottom: 14px; }
        .logo { height: 58px; width: auto; }
        .company { font-size: 17px; font-weight: bold; color: #8B2635; margin-top: 3px; }
        .doc-title { font-size: 22px; font-weight: bold; letter-spacing: 1px; color: #1f1a1b; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 6px; font-weight: bold; font-size: 11px; background: #FBE9EC; color: #8B2635; }
        .pill.ok { background: #E3F4EA; color: #1c6b3f; }
        .pill.part { background: #FFF1DA; color: #8a5a00; }
        .pill.due { background: #FBE9EC; color: #8B2635; }
        .meta td { padding: 2px 0 2px 0; vertical-align: top; }

        /* info boxes */
        .box { background: #F8F4F4; border: 1px solid #EADFE1; border-radius: 6px; padding: 8px 10px; vertical-align: top; }
        .box h4 { margin: 0 0 4px 0; font-size: 11px; color: #8B2635; }
        .gap { width: 12px; }

        /* items */
        table.items { width: 100%; table-layout: fixed; margin: 12px 0 8px 0; }
        table.items thead { display: table-header-group; }
        table.items th { background: #8B2635; color: #ffffff; padding: 6px 6px; font-size: 10px; text-align: left; }
        table.items td { border-bottom: 1px solid #E8DEE0; padding: 6px 6px; vertical-align: top; word-wrap: break-word; }
        table.items tr { page-break-inside: avoid; }
        table.items .num { text-align: right; white-space: nowrap; }
        table.items th.num { text-align: right; }

        /* totals + notes */
        .totals { width: 100%; }
        .totals td { padding: 3px 8px; }
        .totals .grand td { font-weight: bold; font-size: 12.5px; background: #FBE9EC; color: #8B2635; border-radius: 4px; }
        .totals .strong td { font-weight: bold; }
        .note { border: 1px solid #EADFE1; border-radius: 6px; padding: 8px 10px; background: #FBFAFA; }
        .nobreak { page-break-inside: avoid; }

        /* footer anchored at the bottom of EVERY page */
        .footer { position: fixed; left: 0; right: 0; bottom: -44px; border-top: 1px solid #D9CBCE; padding-top: 6px; font-size: 9.5px; color: #5a4f51; }
    </style>
</head>
<body>
    <div class="footer">
        <table class="w100"><tr>
            <td style="width:60%; vertical-align: top;">
                <strong style="color:#8B2635;">{{ $config['company_name'] }}</strong>
                @if($config['address']) <br>{{ $config['address'] }}@endif
                @if($config['contact']) <br>{{ $config['contact'] }}@endif
            </td>
            <td style="width:40%; vertical-align: top;" class="right">{{ $config['footer'] }}</td>
        </tr></table>
    </div>

    <table class="w100 header"><tr>
        <td style="width:55%; vertical-align: middle;">
            @if(!empty($logo_url)) <img class="logo" src="{{ $logo_url }}" alt="logo"><br>@endif
            <div class="company">{{ $config['company_name'] }}</div>
        </td>
        <td style="width:45%; vertical-align: top;" class="right">
            <div class="doc-title">{{ $config['title'] }}</div>
            <table class="meta" style="margin-left:auto;">
                <tr><td colspan="2" class="right"><span class="pill">{{ $order->order_no }}</span></td></tr>
                <tr><td class="muted right" style="padding-right:8px;">Tanggal</td><td class="right">{{ $order->created_at?->format('d-m-Y H:i') }}</td></tr>
                <tr><td class="muted right" style="padding-right:8px;">Status</td><td class="right"><span class="pill {{ $statusTone }}">{{ $statusLabel }}</span></td></tr>
            </table>
        </td>
    </tr></table>

    {{-- A1-22: the recipient block honors show_konsumen. Hiding is always privacy-safe; a consumer-facing
         PDF is still constrained to their own identity, and internal referral identity is never rendered (A1-13). --}}
    @if($showRecipient || ($showInternalReferral && (($config['show_sales'] && $order->sales) || ($config['show_korsal'] && $order->korsal))))
    <table class="w100 nobreak"><tr>
        @if($showRecipient)
        <td class="box" style="width:48%;">
            <h4>Konsumen</h4>
            <strong>{{ $order->recipient_name_snapshot }}</strong><br>
            {{ $order->recipient_phone_snapshot }}
        </td>
        <td class="gap"></td>
        <td class="box" style="width:52%;">
            <h4>Alamat Pengiriman</h4>
            {{ $order->address_snapshot }}<br>
            @if($order->village_snapshot) {{ $order->village_snapshot }},@endif
            @if($order->district_snapshot) {{ $order->district_snapshot }},@endif
            @if($order->regency_snapshot) {{ $order->regency_snapshot }},@endif
            @if($order->province_snapshot) {{ $order->province_snapshot }}@endif
        </td>
        @endif
        {{-- A1-13: internal referral identity is NEVER shown on a consumer-facing invoice, even when branch/global
             show_sales/show_korsal flags are on. Staff (non-owner) viewers retain the permitted content. --}}
        @if($showInternalReferral && (($config['show_sales'] && $order->sales) || ($config['show_korsal'] && $order->korsal)))
        @if($showRecipient)<td class="gap"></td>@endif
        <td class="box" style="width:30%;">
            @if($config['show_sales'] && $order->sales) <strong>Sales</strong><br>{{ $order->sales->name }}<br>@endif
            @if($config['show_korsal'] && $order->korsal) <strong>Korsal</strong><br>{{ $order->korsal->name }}<br>@endif
        </td>
        @endif
    </tr></table>
    @endif

    {{-- COD-only: state the payment method and the ACTUAL payment status. Never "LUNAS" unless the order is paid. --}}
    @if($order->paymentMethod?->type === 'cod')
    <div style="margin-top: 8px;" data-invoice-payment="cod">
        <strong>Metode Pembayaran:</strong> COD / Cash on Delivery (bayar di tempat)<br>
        <strong>Status Pembayaran:</strong>
        @if(($summary['payment_status'] ?? $order->payment_status) === 'paid') LUNAS
        @elseif((float) ($summary['total_paid'] ?? 0) > 0) Dibayar Sebagian
        @else Belum Dibayar (dibayar saat barang diterima)
        @endif
    </div>
    @endif

    @if($config['show_items'])
    <table class="items">
        <thead>
            <tr>
                <th style="width:5%">No</th>
                <th style="width:34%">Produk</th>
                <th style="width:19%">Variasi</th>
                <th class="num" style="width:16%">Harga Satuan</th>
                <th class="num" style="width:7%">Qty</th>
                <th class="num" style="width:19%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td><strong>{{ $item->product_name_snapshot }}</strong></td>
                <td class="muted">{{ $item->variation_label_snapshot }}</td>
                <td class="num">{{ $money($item->unit_price_snapshot) }}</td>
                <td class="num">{{ $item->fulfilled_quantity }}</td>
                <td class="num">{{ $money((float) $item->unit_price_snapshot * $item->fulfilled_quantity) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <table class="w100 nobreak" style="margin-top: 6px;"><tr>
        <td style="width:52%; vertical-align: top; padding-right: 14px;">
            @if($config['show_voucher'] && $order->voucher_id)
            <div class="note" style="margin-bottom:6px;">Voucher diterapkan: diskon {{ $money($order->effectiveDiscountAmount()) }}</div>
            @endif
            @if($config['notes'])
            <div class="note"><strong style="color:#8B2635;">Catatan</strong><br>{{ $config['notes'] }}</div>
            @endif
        </td>
        <td style="width:48%; vertical-align: top;">
            @if($config['show_payment_summary'])
            <table class="totals">
                <tr><td>Subtotal</td><td class="right">{{ $money($order->subtotal_amount) }}</td></tr>
                @if($order->effectiveDiscountAmount() > 0)
                <tr><td>Diskon/Voucher</td><td class="right">- {{ $money($order->effectiveDiscountAmount()) }}</td></tr>
                @endif
                <tr><td>Ongkir</td><td class="right">{{ $money($order->shipping_fee_amount) }}</td></tr>
                <tr><td>Biaya Admin</td><td class="right">{{ $money($order->admin_fee_amount) }}</td></tr>
                <tr class="grand"><td>Total</td><td class="right">{{ $money($order->total_amount) }}</td></tr>
                @if((float) $order->dp_amount > 0)
                <tr><td>DP</td><td class="right">{{ $money($summary['verified_dp']) }}</td></tr>
                @endif
                <tr><td>Dibayar</td><td class="right">{{ $money($summary['total_paid']) }}</td></tr>
                @if((float) $order->remaining_amount > 0)
                <tr class="strong"><td>Sisa Tagihan</td><td class="right">{{ $money($order->remaining_amount) }}</td></tr>
                @endif
            </table>
            @endif
        </td>
    </tr></table>
</body>
</html>
