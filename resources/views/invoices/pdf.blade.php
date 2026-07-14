<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; }
        .wrap { padding: 36px 40px; }
        .head { width: 100%; }
        .brand { color: #1d4ed8; font-size: 26px; font-weight: bold; }
        .muted { color: #64748b; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .items { margin-top: 24px; }
        .items th { background: #f1f5f9; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; color: #475569; }
        .items td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        .right { text-align: right; }
        .totals { margin-top: 12px; width: 260px; float: right; }
        .totals td { padding: 4px 0; }
        .grand { font-size: 15px; font-weight: bold; color: #1d4ed8; border-top: 2px solid #1d4ed8; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .foot { margin-top: 40px; color: #64748b; font-size: 11px; clear: both; }
    </style>
</head>
<body>
<div class="wrap">
    <table class="head">
        <tr>
            <td>
                <div class="brand">Malayznbeat</div>
                <div class="muted">Content &amp; Growth Agency<br>Malaysia</div>
            </td>
            <td class="right">
                <h1>INVOICE</h1>
                <div class="muted">{{ $invoice->code }}</div>
                <div class="badge" style="background:#dbeafe;color:#1d4ed8;">{{ strtoupper($invoice->status) }}</div>
            </td>
        </tr>
    </table>

    <table class="meta" style="margin-top:28px;">
        <tr>
            <td width="55%">
                <div class="muted">Bill to</div>
                <strong>{{ $invoice->contact->business_name ?? 'N/A' }}</strong><br>
                @if($invoice->contact?->email){{ $invoice->contact->email }}<br>@endif
                @if($invoice->contact?->phone){{ $invoice->contact->phone }}<br>@endif
                @if($invoice->contact?->address){{ $invoice->contact->address }}@endif
            </td>
            <td class="right">
                <div><span class="muted">Issue date:</span> {{ $invoice->issue_date?->format('d M Y') }}</div>
                <div><span class="muted">Due date:</span> {{ $invoice->due_date?->format('d M Y') ?? '—' }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Unit price</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                <td class="right">RM {{ number_format($item->unit_price, 2) }}</td>
                <td class="right">RM {{ number_format($item->line_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="right">RM {{ number_format($invoice->subtotal, 2) }}</td></tr>
        @if($invoice->discount_amount > 0)
        <tr><td class="muted">Discount</td><td class="right">- RM {{ number_format($invoice->discount_amount, 2) }}</td></tr>
        @endif
        @if($invoice->tax_rate > 0)
        <tr><td class="muted">Tax ({{ rtrim(rtrim(number_format($invoice->tax_rate, 2), '0'), '.') }}%)</td><td class="right">RM {{ number_format($invoice->tax_amount, 2) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">RM {{ number_format($invoice->total, 2) }}</td></tr>
        @if($invoice->amount_paid > 0)
        <tr><td class="muted">Paid</td><td class="right">RM {{ number_format($invoice->amount_paid, 2) }}</td></tr>
        <tr><td class="muted"><strong>Balance</strong></td><td class="right"><strong>RM {{ number_format($invoice->balance, 2) }}</strong></td></tr>
        @endif
    </table>

    <div class="foot">
        @if($invoice->notes)<p><strong>Notes:</strong> {{ $invoice->notes }}</p>@endif
        <p>{{ $invoice->terms ?? 'Thank you for your business.' }}</p>
    </div>
</div>
</body>
</html>
