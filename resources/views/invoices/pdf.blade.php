@php
    /** @var \App\Models\Invoice $invoice */
    $money = fn ($v) => number_format((float) $v, 2, '.', "'");
    $date = fn ($d) => $d?->format('d.m.Y') ?? '';
@endphp
<!DOCTYPE html>
<html lang="{{ $invoice->language ?: 'en' }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { margin: 0; color: #1a1a1a; font-size: 12px; }
        .wrap { padding: 40px; }
        .row:after { content: ""; display: table; clear: both; }
        .col-left { float: left; width: 50%; }
        .col-right { float: right; width: 45%; text-align: right; }
        h1 { color: #00A878; font-size: 26px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .meta td { padding: 2px 0; }
        .parties { margin-top: 28px; }
        .party-label { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 28px; }
        table.items th { background: #00A878; color: #fff; text-align: left; padding: 8px; font-size: 11px; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .totals { width: 40%; float: right; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 5px 8px; }
        .totals tr.grand td { border-top: 2px solid #00A878; font-weight: bold; font-size: 14px; }
        .notes { clear: both; padding-top: 32px; color: #374151; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px;
            text-transform: uppercase; letter-spacing: .05em; background: #eef2ff; color: #3730a3; }
        .payment-part { margin-top: 36px; border-top: 1px dashed #9ca3af; padding-top: 8px; }
        .fallback { margin-top: 28px; border: 1px solid #e5e7eb; padding: 14px; }
        .fallback td { padding: 3px 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="row">
        <div class="col-left">
            <h1>{{ $entity?->name }}</h1>
            <div class="muted">
                {{ $entity?->legal_name }}<br>
                {{ trim(($entity?->street ?? '').' '.($entity?->street_number ?? '')) }}<br>
                {{ $entity?->postal_code }} {{ $entity?->city }}<br>
                @if ($entity?->uid){{ $entity->uid }}@endif
            </div>
        </div>
        <div class="col-right">
            <div class="status">{{ $invoice->status->getLabel() }}</div>
            <table class="meta" style="width:100%; margin-top:10px;">
                <tr><td class="muted">Invoice</td><td style="text-align:right"><strong>{{ $invoice->invoice_number }}</strong></td></tr>
                <tr><td class="muted">Issue date</td><td style="text-align:right">{{ $date($invoice->issue_date) }}</td></tr>
                <tr><td class="muted">Due date</td><td style="text-align:right">{{ $date($invoice->due_date) }}</td></tr>
                @if ($invoice->reference)
                    <tr><td class="muted">Reference</td><td style="text-align:right">{{ $invoice->reference }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <div class="parties row">
        <div class="col-left">
            <div class="party-label">Bill to</div>
            <strong>{{ $client?->name }}</strong><br>
            <span class="muted">
                {{ $client?->fullAddress() }}<br>
                @if ($client?->vat_number){{ $client->vat_number }}@endif
            </span>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">VAT %</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lineItems as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, '.', "'"), '0'), '.') }}</td>
                    <td class="num">{{ $money($line->unit_price) }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->vat_rate, 2, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td style="text-align:right">{{ $money($invoice->subtotal) }} {{ $invoice->currency_code }}</td></tr>
        <tr><td class="muted">VAT</td><td style="text-align:right">{{ $money($invoice->vat_amount) }} {{ $invoice->currency_code }}</td></tr>
        <tr class="grand"><td>Total</td><td style="text-align:right">{{ $money($invoice->total) }} {{ $invoice->currency_code }}</td></tr>
    </table>

    @if ($invoice->notes)
        <div class="notes"><strong>Notes</strong><br>{{ $invoice->notes }}</div>
    @endif

    @if ($paymentPart)
        <div class="payment-part">{!! $paymentPart !!}</div>
    @elseif ($invoice->qr_reference)
        <div class="fallback">
            <div class="party-label">Payment</div>
            <table>
                <tr><td class="muted">Account</td><td>{{ $invoice->creditor_iban }}</td></tr>
                <tr><td class="muted">Payable to</td><td>{{ $invoice->creditor_name }}</td></tr>
                <tr><td class="muted">Reference</td><td>{{ $invoice->qr_reference }}</td></tr>
                <tr><td class="muted">Amount</td><td>{{ $money($invoice->total) }} {{ $invoice->currency_code }}</td></tr>
            </table>
        </div>
    @endif
</div>
</body>
</html>
