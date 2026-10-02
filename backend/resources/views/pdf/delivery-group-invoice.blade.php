<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 38px; }
        body { color: #262626; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h1 { font-size: 21px; margin: 0 0 5px; }
        h2 { font-size: 13px; margin: 20px 0 8px; }
        .muted { color: #737373; }
        .row { display: table; width: 100%; }
        .cell { display: table-cell; vertical-align: top; width: 50%; }
        table { border-collapse: collapse; margin-top: 18px; width: 100%; }
        th, td { border-bottom: 1px solid #d4d4d4; padding: 8px 5px; text-align: left; }
        th:last-child, td.num { text-align: right; }
        .totals { margin-left: auto; margin-top: 15px; width: 48%; }
        .totals td { border: 0; padding: 4px 0; }
        .grand { border-top: 1px solid #262626 !important; font-size: 13px; font-weight: bold; }
        .note { background: #f5f5f4; margin-top: 18px; padding: 10px; }
    </style>
</head>
<body>
    <div class="row">
        <div class="cell">
            <h1>Prime Classy</h1>
            <div class="muted">Invoice delivery group</div>
        </div>
        <div class="cell" style="text-align:right">
            <strong>{{ $invoice_number }}</strong><br>
            Order {{ $order->order_no }}<br>
            Tanggal Pengiriman: {{ \Illuminate\Support\Carbon::parse($delivery_date)->locale('id')->translatedFormat('j F Y') }}
        </div>
    </div>

    <h2>Pelanggan</h2>
    <div>{{ $order->recipient_name_snapshot }}</div>
    <div>{{ $order->recipient_phone_snapshot }}</div>
    <div>{{ $order->address_snapshot }}</div>

    <h2>Pengiriman</h2>
    <div>Pengirim: {{ implode(', ', $delivery_methods) }}</div>
    @if ($courier_names)
        <div>Nama kurir: {{ implode(', ', $courier_names) }}</div>
    @endif
    @if ($tracking_numbers)
        <div>No. resi: {{ implode(', ', $tracking_numbers) }}</div>
    @endif

    <table>
        <thead>
            <tr><th>Produk</th><th>SKU</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['product_name'] }}@if ($item['variation_label'])<br><span class="muted">{{ $item['variation_label'] }}</span>@endif</td>
                    <td>{{ $item['sku'] ?: '-' }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">Rp {{ $item['unit_price'] }}</td>
                    <td class="num">Rp {{ $item['subtotal'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Total item</td><td class="num">{{ $total_item_count }}</td></tr>
        <tr><td>Subtotal item grup</td><td class="num">Rp {{ $item_subtotal }}</td></tr>
        <tr><td>Ongkir grup</td><td class="num">Rp {{ $shipping_fee }}</td></tr>
        <tr class="grand"><td>Total grup</td><td class="num">Rp {{ $group_total }}</td></tr>
    </table>

    <div class="note">
        <strong>Pembayaran order</strong><br>
        Metode: {{ $payment['method'] ?: '-' }}. Status: {{ $payment['status'] }}. Sisa {{ $payment['order_remaining'] }} adalah saldo seluruh order, bukan saldo khusus grup ini.
        @if ($payment['requested_dp'])
            <br>DP awal pada grup tanggal paling awal: Rp {{ $payment['requested_dp'] }}.
            @if ($payment['verified_dp_credit'])
                Kredit DP terverifikasi: Rp {{ $payment['verified_dp_credit'] }}.
            @else
                DP belum terverifikasi dan belum dihitung sebagai kredit.
            @endif
        @elseif ($payment['has_down_payment'] && $payment['dp_credit_date'])
            <br>DP awal dicatat pada grup tanggal paling awal ({{ \Illuminate\Support\Carbon::parse($payment['dp_credit_date'])->format('d/m/Y') }}); nominalnya tidak dialokasikan ke grup ini.
        @endif
        @if ($payment['admin_fee_order_level'] !== '0,00' || $payment['discount_order_level'] !== '0,00')
            <br>Biaya admin order: Rp {{ $payment['admin_fee_order_level'] }}; diskon order: Rp {{ $payment['discount_order_level'] }}. Keduanya tetap pada tingkat order dan tidak dialokasikan ke total grup.
        @endif
    </div>
</body>
</html>