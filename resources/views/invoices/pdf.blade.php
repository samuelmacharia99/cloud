<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            color: #1f2937;
            background: white;
            font-size: 12px;
            line-height: 1.5;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 0;
            background: white;
        }

        /* ===== HEADER / LETTERHEAD ===== */
        .header-band {
            background-color: {{ $company['color'] ?? '#2563eb' }};
            color: white;
            padding: 25px 40px;
            margin-bottom: 30px;
        }

        .header-content {
            display: table;
            width: 100%;
        }

        .header-left {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            padding-right: 20px;
        }

        .header-left img {
            max-width: 120px;
            max-height: 80px;
            margin-bottom: 12px;
            display: block;
        }

        .company-info {
            font-size: 11px;
            line-height: 1.6;
        }

        .company-info .name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .company-info .detail {
            opacity: 0.95;
        }

        .company-info .vat {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 4px;
        }

        .header-right {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            text-align: right;
            padding-left: 20px;
        }

        .invoice-title {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .invoice-meta {
            font-size: 11px;
            line-height: 1.8;
        }

        .invoice-meta .label {
            opacity: 0.85;
        }

        .invoice-meta .value {
            font-weight: 600;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            margin-top: 10px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #22c55e;
        }

        .status-unpaid {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .status-overdue {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* ===== CONTENT PADDING ===== */
        .content {
            padding: 0 40px 40px 40px;
        }

        /* ===== ADDRESSES ===== */
        .addresses {
            display: table;
            width: 100%;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 25px;
        }

        .address-block {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            padding-right: 30px;
            font-size: 11px;
            line-height: 1.7;
        }

        .address-block .label {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .address-block .name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .address-block.provider .name {
            color: {{ $company['color'] ?? '#2563eb' }};
        }

        .address-block .detail {
            color: #4b5563;
        }

        /* ===== ITEMS TABLE ===== */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 11px;
        }

        .items-table thead {
            background-color: {{ $company['color'] ?? '#2563eb' }};
            color: white;
        }

        .items-table thead th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table thead th.right {
            text-align: right;
        }

        .items-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .items-table tbody tr:last-child td {
            border-bottom: 2px solid #e5e7eb;
        }

        .items-table .item-desc {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 2px;
        }

        .items-table .item-detail {
            font-size: 10px;
            color: #6b7280;
            margin-top: 3px;
        }

        .items-table .num {
            width: 5%;
            text-align: center;
            color: #999;
        }

        .items-table .description {
            width: 45%;
        }

        .items-table .period {
            width: 20%;
            color: #6b7280;
            font-size: 10px;
        }

        .items-table .qty,
        .items-table .unit-price,
        .items-table .amount {
            width: 12%;
            text-align: right;
            font-weight: 500;
        }

        /* ===== TOTALS ===== */
        .totals-section {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .totals-spacer {
            display: table-cell;
            width: 50%;
        }

        .totals-block {
            display: table-cell;
            width: 50%;
            padding-left: 40px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .totals-table tr {
            height: 24px;
        }

        .totals-table td {
            padding: 6px 0;
        }

        .totals-table td:first-child {
            text-align: right;
            color: #4b5563;
            padding-right: 15px;
            width: 55%;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: 600;
            color: #1f2937;
            width: 45%;
        }

        .totals-table .subtotal,
        .totals-table .tax {
            border-bottom: 1px solid #e5e7eb;
        }

        .totals-table .total td {
            font-size: 13px;
            font-weight: bold;
            color: white;
            background-color: {{ $company['color'] ?? '#2563eb' }};
            padding: 10px 12px;
            border-radius: 3px;
        }

        .totals-table .total td:first-child {
            text-align: right;
            padding-right: 15px;
        }

        .totals-table .paid,
        .totals-table .balance {
            border-top: 2px solid #e5e7eb;
        }

        .totals-table .balance td:last-child {
            color: #ef4444;
        }

        /* ===== PAYMENTS RECEIVED ===== */
        .payments-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1f2937;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid {{ $company['color'] ?? '#2563eb' }};
            padding-bottom: 6px;
        }

        .payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .payments-table thead {
            background-color: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        .payments-table thead th {
            padding: 8px;
            text-align: left;
            font-weight: bold;
            color: #4b5563;
            font-size: 9px;
            text-transform: uppercase;
        }

        .payments-table tbody td {
            padding: 8px;
            border-bottom: 1px solid #f3f4f6;
        }

        .payments-table .amount {
            text-align: right;
            font-weight: 600;
        }

        /* ===== PAYMENT INSTRUCTIONS ===== */
        .payment-instructions {
            background-color: #f0f9ff;
            border: 1px solid #bfdbfe;
            border-left: 4px solid {{ $company['color'] ?? '#2563eb' }};
            padding: 15px;
            margin-bottom: 30px;
            font-size: 11px;
        }

        .payment-instructions .title {
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
            font-size: 11px;
        }

        .payment-method {
            margin-bottom: 10px;
            color: #1e40af;
        }

        .payment-method .method-name {
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }

        .payment-method .method-detail {
            margin-left: 15px;
            font-size: 10px;
            color: #1e40af;
        }

        /* ===== NOTES ===== */
        .notes-section {
            background-color: #fef9e7;
            border-left: 4px solid #f59e0b;
            padding: 12px;
            margin-bottom: 30px;
            font-size: 11px;
        }

        .notes-section .label {
            font-weight: bold;
            color: #92400e;
            margin-bottom: 6px;
            font-size: 10px;
        }

        .notes-section .content {
            color: #78350f;
            line-height: 1.5;
        }

        /* ===== FOOTER ===== */
        .footer {
            border-top: 1px solid #e5e7eb;
            padding: 20px 40px;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
            line-height: 1.6;
        }

        .footer .company-footer {
            margin-bottom: 8px;
            font-weight: 500;
        }

        .footer .legal {
            font-size: 8px;
            opacity: 0.7;
        }

        /* ===== WATERMARK (for paid invoices) ===== */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            opacity: 0.08;
            color: #22c55e;
            z-index: -1;
            pointer-events: none;
            white-space: nowrap;
            font-weight: bold;
        }
    </style>
</head>
<body>
    @if($invoice->status === 'paid')
        <div class="watermark">PAID</div>
    @endif

    <div class="container">
        <!-- HEADER / LETTERHEAD -->
        <div class="header-band">
            <div class="header-content">
                <div class="header-left">
                    @if($company['logo'])
                        <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">
                    @endif
                    <div class="company-info">
                        <div class="name">{{ $company['name'] }}</div>
                        @if($company['address'])
                            <div class="detail">{{ $company['address'] }}</div>
                        @endif
                        @if($company['city'] || $company['country'])
                            <div class="detail">{{ $company['city'] }}@if($company['country']), {{ $company['country'] }}@endif</div>
                        @endif
                        @if($company['email'])
                            <div class="detail">{{ $company['email'] }}</div>
                        @endif
                        @if($company['vat'])
                            <div class="vat">TAX ID: {{ $company['vat'] }}</div>
                        @endif
                    </div>
                </div>
                <div class="header-right">
                    <div class="invoice-title">TAX INVOICE</div>
                    <div class="invoice-meta">
                        <div><span class="label">Invoice #</span></div>
                        <div style="font-family: monospace; font-weight: bold; font-size: 14px; margin-bottom: 12px;">{{ $invoice->invoice_number }}</div>
                        <div><span class="label">Date:</span> <span class="value">{{ $invoice->created_at->format('M d, Y') }}</span></div>
                        @if($invoice->due_date)
                            <div><span class="label">Due Date:</span> <span class="value">{{ $invoice->due_date->format('M d, Y') }}</span></div>
                        @endif
                    </div>
                    <div class="status-badge status-{{ $invoice->status }}">
                        {{ ucfirst($invoice->status) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <!-- BILLING ADDRESSES -->
            <div class="addresses">
                <div class="address-block">
                    <div class="label">Billed To</div>
                    <div class="name">{{ $user->name }}</div>
                    @if($user->email)
                        <div class="detail">{{ $user->email }}</div>
                    @endif
                    @if($user->phone)
                        <div class="detail">{{ $user->phone }}</div>
                    @endif
                    @if($user->address)
                        <div class="detail">{{ $user->address }}</div>
                    @endif
                    @if($user->city || $user->postal_code)
                        <div class="detail">{{ $user->city }}@if($user->postal_code) {{ $user->postal_code }}@endif</div>
                    @endif
                </div>
                <div class="address-block provider">
                    <div class="label">Service Provider</div>
                    <div class="name">{{ $company['name'] }}</div>
                    @if($company['address'])
                        <div class="detail">{{ $company['address'] }}</div>
                    @endif
                    @if($company['city'] || $company['country'])
                        <div class="detail">{{ $company['city'] }}@if($company['country']), {{ $company['country'] }}@endif</div>
                    @endif
                    @if($company['vat'])
                        <div class="detail" style="font-size: 10px; margin-top: 4px;">Tax: {{ $company['vat'] }}</div>
                    @endif
                </div>
            </div>

            <!-- LINE ITEMS TABLE -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 3%;">#</th>
                        <th style="width: 40%;">Description</th>
                        <th style="width: 20%;">Period / Details</th>
                        <th class="right qty" style="width: 9%;">Qty</th>
                        <th class="right unit-price" style="width: 14%;">Unit Price</th>
                        <th class="right amount" style="width: 14%;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $index => $item)
                        <tr>
                            <td class="num">{{ $index + 1 }}</td>
                            <td class="description">
                                <div class="item-desc">{{ $item->product->name ?? 'Service' }}</div>
                                @if($item->description && $item->product)
                                    <div class="item-detail">{{ $item->description }}</div>
                                @endif
                                @if($item->service)
                                    <div class="item-detail">
                                        Service Type: {{ $item->service->product->name ?? 'Service' }}
                                    </div>
                                @endif
                            </td>
                            <td class="period">
                                @if($item->service && $item->service->billing_cycle)
                                    {{ ucfirst($item->service->billing_cycle) }} Billing
                                @elseif($item->description && !$item->product)
                                    {{ $item->description }}
                                @else
                                    One-time
                                @endif
                            </td>
                            <td class="qty">{{ intval($item->quantity) }}</td>
                            <td class="unit-price">{{ $currencySymbol }} {{ number_format($item->unit_price, 2) }}</td>
                            <td class="amount">{{ $currencySymbol }} {{ number_format($item->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; color: #6b7280; padding: 20px;">No items in this invoice</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- TOTALS -->
            <div class="totals-section">
                <div class="totals-spacer"></div>
                <div class="totals-block">
                    <table class="totals-table">
                        @if($invoice->subtotal > 0)
                            <tr class="subtotal">
                                <td>Subtotal</td>
                                <td>{{ $currencySymbol }} {{ number_format($invoice->subtotal, 2) }}</td>
                            </tr>
                        @endif
                        @if($tax['enabled'] && $invoice->tax > 0)
                            <tr class="tax">
                                <td>{{ $tax['name'] }} ({{ $tax['rate'] }}%)</td>
                                <td>{{ $currencySymbol }} {{ number_format($invoice->tax, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="total">
                            <td>Total Due</td>
                            <td>{{ $currencySymbol }} {{ number_format($invoice->total, 2) }}</td>
                        </tr>
                        @if($amountPaid > 0)
                            <tr class="paid">
                                <td>Amount Paid</td>
                                <td>{{ $currencySymbol }} {{ number_format($amountPaid, 2) }}</td>
                            </tr>
                        @endif
                        @if($amountRemaining > 0)
                            <tr class="balance">
                                <td>Balance Due</td>
                                <td>{{ $currencySymbol }} {{ number_format($amountRemaining, 2) }}</td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>

            <!-- PAYMENTS RECEIVED -->
            @if($invoice->payments && $invoice->payments->where('status', 'completed')->count() > 0)
                <div class="payments-section">
                    <div class="section-title">Payments Received</div>
                    <table class="payments-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                                <th class="amount">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->payments->where('status', 'completed') as $payment)
                                <tr>
                                    <td>{{ $payment->paid_at?->format('M d, Y') ?? 'N/A' }}</td>
                                    <td>{{ ucfirst($payment->payment_method) }}</td>
                                    <td>{{ $payment->transaction_reference ?? '—' }}</td>
                                    <td class="amount">{{ $currencySymbol }} {{ number_format($payment->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <!-- PAYMENT INSTRUCTIONS -->
            @if($paymentMethods['mpesa'] || $paymentMethods['bank'] || $paymentMethods['stripe'] || $paymentMethods['paypal'])
                <div class="payment-instructions">
                    <div class="title">How to Pay</div>

                    @if($paymentMethods['mpesa'])
                        <div class="payment-method">
                            <span class="method-name">M-Pesa (Mobile Money)</span>
                            <div class="method-detail">
                                Paybill: <strong>{{ $mpesaShortcode }}</strong><br>
                                Account: <strong>{{ $invoice->invoice_number }}</strong>
                            </div>
                        </div>
                    @endif

                    @if($paymentMethods['bank'])
                        <div class="payment-method">
                            <span class="method-name">Bank Transfer</span>
                            <div class="method-detail">
                                @if($bank['name'])
                                    {{ $bank['name'] }}<br>
                                @endif
                                Account: <strong>{{ $bank['account'] }}</strong><br>
                                @if($bank['holder'])
                                    Name: {{ $bank['holder'] }}<br>
                                @endif
                                @if($bank['swift'])
                                    SWIFT: {{ $bank['swift'] }}
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($paymentMethods['stripe'] || $paymentMethods['paypal'])
                        <div class="payment-method">
                            <span class="method-name">Online Payment</span>
                            <div class="method-detail">
                                Visit: {{ $siteUrl }}/invoices/{{ $invoice->id }}
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <!-- NOTES -->
            @if($invoice->notes)
                <div class="notes-section">
                    <div class="label">Notes</div>
                    <div class="content">{{ $invoice->notes }}</div>
                </div>
            @endif
        </div>

        <!-- FOOTER -->
        <div class="footer">
            @if($company['footer'])
                <div class="company-footer">{{ $company['footer'] }}</div>
            @endif
            <div class="legal">
                This invoice was generated on {{ now()->format('F d, Y H:i') }} and is computer-generated — no signature required.<br>
                For inquiries, contact {{ $company['email'] ?? 'support@example.com' }}
            </div>
        </div>
    </div>
</body>
</html>
