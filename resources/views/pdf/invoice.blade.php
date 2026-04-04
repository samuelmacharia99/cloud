<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
        }
        .company-info {
            flex: 1;
        }
        .company-logo {
            max-width: 150px;
            max-height: 60px;
            margin-bottom: 10px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 10px;
        }
        .company-details {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.6;
        }
        .invoice-header {
            text-align: right;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 10px;
        }
        .invoice-number {
            font-size: 14px;
            color: #6b7280;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 12px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .bill-to {
            float: left;
            width: 45%;
        }
        .invoice-details {
            float: right;
            width: 45%;
            text-align: right;
        }
        .bill-to-name {
            font-size: 14px;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .bill-to-info {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.6;
        }
        .invoice-detail-row {
            margin-bottom: 10px;
        }
        .invoice-detail-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 3px;
        }
        .invoice-detail-value {
            font-size: 13px;
            font-weight: bold;
            color: #1f2937;
        }
        .clearfix {
            clear: both;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table thead {
            background-color: #f9fafb;
            border-bottom: 2px solid #d1d5db;
        }
        table th {
            padding: 10px;
            text-align: left;
            font-size: 12px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
        }
        table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
        }
        table tr:last-child td {
            border-bottom: none;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .amount {
            font-weight: bold;
            color: #1f2937;
        }
        .totals {
            width: 45%;
            float: right;
            margin-bottom: 20px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
        }
        .total-row.total {
            border-bottom: none;
            border-top: 2px solid #e5e7eb;
            padding-top: 12px;
            font-weight: bold;
            font-size: 14px;
            color: #1f2937;
            background-color: #f9fafb;
            padding: 12px 8px;
            margin: 10px -8px 0 -8px;
        }
        .total-label {
            flex: 1;
        }
        .total-amount {
            text-align: right;
        }
        .notes {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #6b7280;
        }
        .notes-title {
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .payments {
            margin-bottom: 20px;
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
            margin-top: 30px;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
        }
        .thank-you {
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 10px;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                @if($settings['logo_url'])
                    <img src="{{ $settings['logo_url'] }}" alt="Logo" class="company-logo">
                @endif
                <div class="company-name">{{ $settings['company_name'] }}</div>
                <div class="company-details">
                    @if($settings['billing_address'])
                        {{ $settings['billing_address'] }}<br>
                    @endif
                    @if($settings['billing_city'])
                        {{ $settings['billing_city'] }}
                        @if($settings['billing_country'])
                            , {{ $settings['billing_country'] }}
                        @endif
                        <br>
                    @endif
                    @if($settings['billing_vat_number'])
                        VAT: {{ $settings['billing_vat_number'] }}
                    @endif
                </div>
            </div>
            <div class="invoice-header">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            </div>
        </div>

        <!-- Bill To & Invoice Details -->
        <div class="section">
            <div class="bill-to">
                <div class="section-title">Bill To</div>
                <div class="bill-to-name">{{ $invoice->user->name }}</div>
                <div class="bill-to-info">
                    {{ $invoice->user->email }}<br>
                    @if($invoice->user->address)
                        {{ $invoice->user->address }}<br>
                    @endif
                    @if($invoice->user->city)
                        {{ $invoice->user->city }}
                        @if($invoice->user->postal_code)
                            {{ $invoice->user->postal_code }}
                        @endif
                    @endif
                </div>
            </div>

            <div class="invoice-details">
                <div class="invoice-detail-row">
                    <div class="invoice-detail-label">Invoice Date</div>
                    <div class="invoice-detail-value">{{ $invoice->created_at->format('F d, Y') }}</div>
                </div>
                @if($invoice->due_date)
                    <div class="invoice-detail-row">
                        <div class="invoice-detail-label">Due Date</div>
                        <div class="invoice-detail-value">{{ $invoice->due_date->format('F d, Y') }}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="clearfix"></div>

        <!-- Line Items -->
        @if($invoice->items->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->items as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->product->name ?? 'Unknown' }}</strong><br>
                                <small>{{ $item->description }}</small>
                            </td>
                            <td class="text-right">{{ $item->quantity }}</td>
                            <td class="text-right">Ksh {{ number_format($item->unit_price, 0) }}</td>
                            <td class="text-right amount">Ksh {{ number_format($item->amount, 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <!-- Totals -->
        <div class="totals">
            <div class="total-row">
                <div class="total-label">Subtotal</div>
                <div class="total-amount">Ksh {{ number_format($invoice->subtotal, 0) }}</div>
            </div>
            @if($invoice->tax > 0)
                <div class="total-row">
                    <div class="total-label">Tax</div>
                    <div class="total-amount">Ksh {{ number_format($invoice->tax, 0) }}</div>
                </div>
            @endif
            <div class="total-row total">
                <div class="total-label">Total Due</div>
                <div class="total-amount">Ksh {{ number_format($invoice->total, 0) }}</div>
            </div>
        </div>

        <div class="clearfix"></div>

        <!-- Payments Received -->
        @if($invoice->payments->count() > 0)
            <div class="payments">
                <div class="section-title">Payments Received</div>
                @foreach($invoice->payments->where('status.value', 'completed') as $payment)
                    <div class="payment-row">
                        <div>Ksh {{ number_format($payment->amount, 0) }} • {{ $payment->payment_method?->label() }}</div>
                        <div>{{ $payment->paid_at?->format('F d, Y') ?? $payment->created_at->format('F d, Y') }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Notes -->
        @if($invoice->notes)
            <div class="notes">
                <div class="notes-title">Notes</div>
                <div>{{ $invoice->notes }}</div>
            </div>
        @endif

        <!-- Thank You -->
        <div class="thank-you">
            Thank you for your business!
        </div>

        <!-- Footer -->
        <div class="footer">
            @if($settings['footer_text'])
                {{ $settings['footer_text'] }}
            @else
                © {{ now()->year }} {{ $settings['company_name'] }}. All rights reserved.
            @endif
        </div>
    </div>
</body>
</html>
