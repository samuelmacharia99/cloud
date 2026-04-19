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
            font-family: 'Arial', sans-serif;
            color: #333;
            background-color: white;
            font-size: 11px;
            line-height: 1.4;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 30px;
        }
        .header-left {
            flex: 1;
        }
        .header-left h1 {
            font-size: 32px;
            color: #2563eb;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        .header-left p {
            color: #666;
            margin: 3px 0;
            font-size: 10px;
        }
        .company-logo {
            max-width: 150px;
            margin-bottom: 10px;
        }

        .header-right {
            text-align: right;
        }
        .header-right h2 {
            font-size: 28px;
            color: #1f2937;
            margin: 0 0 15px 0;
            font-weight: bold;
        }
        .invoice-meta {
            margin: 8px 0;
            font-size: 10px;
        }
        .invoice-meta strong {
            display: inline-block;
            width: 80px;
            color: #666;
        }
        .invoice-meta span {
            color: #333;
            font-weight: bold;
        }

        /* Bill To / Bill From */
        .billing-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            gap: 40px;
        }
        .bill-to, .bill-from {
            flex: 1;
        }
        .bill-to h3, .bill-from h3 {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .bill-to p, .bill-from p {
            margin: 4px 0;
            font-size: 11px;
        }
        .bill-to .company-name {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .bill-from .company-name {
            font-weight: bold;
            font-size: 12px;
            color: #2563eb;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table thead {
            background-color: #f3f4f6;
            border-bottom: 2px solid #2563eb;
        }
        .items-table thead th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            font-size: 11px;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .items-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }
        .items-table tbody tr:last-child td {
            border-bottom: none;
        }
        .items-table .description {
            font-weight: bold;
            color: #1f2937;
        }
        .items-table .notes {
            font-size: 10px;
            color: #6b7280;
            margin-top: 3px;
        }
        .items-table .quantity,
        .items-table .unit-price,
        .items-table .amount {
            text-align: right;
        }

        /* Totals */
        .totals {
            float: right;
            width: 350px;
            margin-bottom: 30px;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table tr {
            height: 25px;
        }
        .totals-table td {
            padding: 8px 12px;
            font-size: 11px;
        }
        .totals-table td:first-child {
            text-align: right;
            font-weight: bold;
            color: #666;
            width: 60%;
        }
        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
            color: #333;
        }
        .totals-table .subtotal td {
            border-bottom: 1px solid #e5e7eb;
        }
        .totals-table .tax td {
            border-bottom: 2px solid #2563eb;
        }
        .totals-table .total td {
            font-size: 14px;
            font-weight: bold;
            color: #2563eb;
            background-color: #f0f9ff;
        }

        /* Notes & Payment Terms */
        .notes-section {
            clear: both;
            margin-top: 50px;
            padding: 20px;
            background-color: #f9fafb;
            border-left: 4px solid #2563eb;
        }
        .notes-section h4 {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: bold;
            color: #666;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        .notes-section p {
            font-size: 10px;
            line-height: 1.5;
            color: #4b5563;
        }

        /* Payment Methods */
        .payment-methods {
            margin-top: 30px;
            padding: 15px;
            background-color: #f0f9ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
        }
        .payment-methods h4 {
            font-size: 11px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .payment-methods p {
            font-size: 10px;
            color: #1e40af;
            margin: 4px 0;
        }

        /* Footer */
        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 10px;
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
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if($company['logo'])
                    <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}" class="company-logo">
                @endif
                <h1>INVOICE</h1>
                @if($company['name'])
                    <p class="company-name">{{ $company['name'] }}</p>
                @endif
                @if($company['address'])
                    <p>{{ $company['address'] }}</p>
                @endif
                @if($company['phone'])
                    <p>Phone: {{ $company['phone'] }}</p>
                @endif
                @if($company['email'])
                    <p>Email: {{ $company['email'] }}</p>
                @endif
            </div>
            <div class="header-right">
                <h2 style="color: {{ $invoice->status === 'paid' ? '#22c55e' : '#f59e0b' }};">
                    {{ strtoupper($invoice->status) }}
                </h2>
                <div class="invoice-meta">
                    <strong>Invoice #:</strong>
                    <span>{{ $invoice->invoice_number }}</span>
                </div>
                <div class="invoice-meta">
                    <strong>Date:</strong>
                    <span>{{ $invoice->created_at->format('F d, Y') }}</span>
                </div>
                @if($invoice->due_date)
                    <div class="invoice-meta">
                        <strong>Due Date:</strong>
                        <span>{{ $invoice->due_date->format('F d, Y') }}</span>
                    </div>
                @endif
            </div>
        </div>

        <!-- Billing Information -->
        <div class="billing-section">
            <div class="bill-to">
                <h3>Bill To</h3>
                <div class="company-name">{{ $user->name }}</div>
                @if($user->email)
                    <p>{{ $user->email }}</p>
                @endif
                @if($user->phone)
                    <p>{{ $user->phone }}</p>
                @endif
                @if($user->address)
                    <p>{{ $user->address }}</p>
                @endif
                @if($user->city)
                    <p>{{ $user->city }}@if($user->postal_code), {{ $user->postal_code }}@endif</p>
                @endif
            </div>

            <div class="bill-from">
                <h3>From</h3>
                <div class="company-name">{{ $company['name'] ?? 'Talksasa Cloud' }}</div>
                @if($company['address'])
                    <p>{{ $company['address'] }}</p>
                @endif
                @if($company['phone'])
                    <p>{{ $company['phone'] }}</p>
                @endif
                @if($company['email'])
                    <p>{{ $company['email'] }}</p>
                @endif
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 15%; text-align: right;">Quantity</th>
                    <th style="width: 15%; text-align: right;">Unit Price</th>
                    <th style="width: 20%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>
                            <div class="description">{{ $item->product->name ?? 'Product' }}</div>
                            @if($item->description)
                                <div class="notes">{{ $item->description }}</div>
                            @endif
                        </td>
                        <td class="quantity">{{ $item->quantity }}</td>
                        <td class="unit-price">Ksh {{ number_format($item->unit_price, 2) }}</td>
                        <td class="amount">Ksh {{ number_format($item->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: #6b7280;">No items</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <table class="totals-table">
                @if($invoice->subtotal && $invoice->subtotal != $invoice->total)
                    <tr class="subtotal">
                        <td>Subtotal</td>
                        <td>Ksh {{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->tax > 0)
                        <tr class="tax">
                            <td>Tax</td>
                            <td>Ksh {{ number_format($invoice->tax, 2) }}</td>
                        </tr>
                    @endif
                @endif
                <tr class="total">
                    <td>Total Due</td>
                    <td>Ksh {{ number_format($invoice->total, 2) }}</td>
                </tr>
                @php $remaining = $invoice->getAmountRemaining(); @endphp
                @if($remaining > 0)
                    <tr class="subtotal">
                        <td>Balance Due</td>
                        <td>Ksh {{ number_format($remaining, 2) }}</td>
                    </tr>
                @endif
            </table>
        </div>

        <!-- Notes Section -->
        @if($invoice->notes)
            <div class="notes-section">
                <h4>Notes</h4>
                <p>{{ $invoice->notes }}</p>
            </div>
        @endif

        <!-- Payment Methods -->
        <div class="payment-methods">
            <h4>Payment Methods Available</h4>
            <p>✓ M-Pesa (Mobile Money)</p>
            <p>✓ Stripe (Credit/Debit Cards)</p>
            <p>✓ PayPal (Online Payment)</p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>This invoice was generated on {{ now()->format('F d, Y H:i') }}</p>
            <p>For questions or concerns, please contact {{ $company['email'] ?? 'support@talksasa.cloud' }}</p>
        </div>
    </div>
</body>
</html>
