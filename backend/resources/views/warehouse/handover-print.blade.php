<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $handover->handover_number }}</title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: Arial, sans-serif; color: #222; font-size: 12px; }
        h1 { margin-bottom: 4px; } table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { border: 1px solid #888; padding: 7px; text-align: left; } th:last-child, td:last-child { text-align: right; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; } .signatures { display: flex; justify-content: space-between; margin-top: 80px; }
        .signature { width: 40%; border-top: 1px solid #222; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>Prime Classy Cake &amp; Cookies</h1><h2>Stock Handover</h2>
    <div class="meta">
        <div>Agent: {{ $handover->agent->name }}</div><div>Printed: {{ now()->format('Y-m-d H:i') }}</div>
        <div>Handover: {{ $handover->handover_number }}</div><div>Transfer: {{ $handover->transfer->transfer_number }}</div>
        <div>Source: {{ $handover->transfer->source_stock_type }}{{ $handover->transfer->sourceSubLocation ? ' — '.$handover->transfer->sourceSubLocation->name.' ('.$handover->transfer->sourceSubLocation->code.')' : '' }}</div><div>Destination: {{ $handover->transfer->destination_stock_type }}{{ $handover->transfer->destinationSubLocation ? ' — '.$handover->transfer->destinationSubLocation->name.' ('.$handover->transfer->destinationSubLocation->code.')' : '' }}</div>
        <div>Handed over by: {{ $handover->handedOverBy->name }}</div><div>Received by: {{ $handover->receivedBy?->name ?? '-' }}</div>
    </div>
    <table><thead><tr><th>SKU</th><th>Product / Variation</th><th>Qty</th></tr></thead><tbody>
    @foreach ($handover->transfer->items as $item)
        <tr><td>{{ $item->variation?->sku ?? $item->product?->sku ?? '-' }}</td><td>{{ $item->variation?->label() ?? $item->product?->name }}</td><td>{{ $item->quantity }}</td></tr>
    @endforeach
    </tbody></table>
    <p>Notes: {{ $handover->note ?? '-' }}</p>
    <div class="signatures"><div class="signature">Handed over by</div><div class="signature">Received by</div></div>
</body>
</html>