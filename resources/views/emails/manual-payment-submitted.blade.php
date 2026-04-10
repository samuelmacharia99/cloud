<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; margin: 0; padding: 20px 0; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 30px; text-align: center; color: white; }
        .company-name { font-size: 24px; font-weight: bold; margin: 0; }
        .content { padding: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table th { background-color: #f3f4f6; padding: 12px; text-align: left; font-weight: bold; border-bottom: 1px solid #e5e7eb; }
        table td { padding: 12px; border-bottom: 1px solid #e5e7eb; }
        .alert { border-radius: 6px; padding: 16px; margin: 20px 0; }
        .alert-warning { background-color: #fef3c7; border: 1px solid #fcd34d; color: #92400e; }
        .cta-button { display: inline-block; background-color: #3b82f6; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; }
        .footer { padding: 30px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">{{ \App\Models\Setting::getValue('site_name', 'Talksasa Cloud') }}</div>
        </div>

        <!-- Content -->
        <div class="content">
            <h2 style="color: #1f2937; margin-bottom: 16px;">New Manual Payment Submission</h2>

            <p style="margin-bottom: 16px; color: #666;">
                A customer has submitted payment details for manual verification. Please review and approve if the payment matches your records.
            </p>

            <!-- Customer Details -->
            <h3 style="color: #374151; font-size: 16px; margin: 20px 0 10px;">Customer Details</h3>
            <table>
                <tr>
                    <td><strong>Name:</strong> {{ $customer->name }}</td>
                </tr>
                <tr>
                    <td><strong>Email:</strong> {{ $customer->email }}</td>
                </tr>
                <tr>
                    <td><strong>Phone:</strong> {{ $customer->phone_number ?? 'N/A' }}</td>
                </tr>
            </table>

            <!-- Invoice Details -->
            <h3 style="color: #374151; font-size: 16px; margin: 20px 0 10px;">Invoice Details</h3>
            <table>
                <tr>
                    <td><strong>Invoice #:</strong></td>
                    <td>{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td><strong>Amount Due:</strong></td>
                    <td style="color: #f59e0b; font-weight: bold;">Ksh {{ number_format($invoice->total, 2) }}</td>
                </tr>
                <tr>
                    <td><strong>Due Date:</strong></td>
                    <td>{{ $invoice->due_date->format('F d, Y') }}</td>
                </tr>
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>{{ ucfirst($invoice->status) }}</td>
                </tr>
            </table>

            <!-- Payment Submission Details -->
            @php
                $details = json_decode($payment->notes, true) ?? [];
            @endphp
            <h3 style="color: #374151; font-size: 16px; margin: 20px 0 10px;">Payment Details Provided</h3>
            <table>
                <tr>
                    <td><strong>Payment Reference:</strong></td>
                    <td>{{ $details['payment_reference'] ?? 'Not provided' }}</td>
                </tr>
                <tr>
                    <td><strong>Bank/Method:</strong></td>
                    <td>{{ $details['bank_name'] ?? 'Not provided' }}</td>
                </tr>
                <tr>
                    <td><strong>Account Name:</strong></td>
                    <td>{{ $details['account_name'] ?? 'Not provided' }}</td>
                </tr>
                <tr>
                    <td><strong>Additional Notes:</strong></td>
                    <td>{{ $details['notes'] ?? 'None' }}</td>
                </tr>
            </table>

            <!-- Action Required -->
            <div class="alert alert-warning">
                <strong>⚠️ Action Required:</strong> Please verify that the customer's payment has been received using the details provided above. Once verified, approve the payment in the admin panel to activate their services.
            </div>

            <!-- Admin Action Link -->
            <div style="text-align: center; margin: 20px 0;">
                <a href="{{ route('admin.payments.index') }}" class="cta-button">Review Payment in Admin Panel</a>
            </div>

            <!-- Payment ID Reference -->
            <p style="color: #999; font-size: 12px; text-align: center; margin-top: 20px;">
                Payment ID: {{ $payment->transaction_reference }}
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>© {{ now()->year }} {{ \App\Models\Setting::getValue('site_name', 'Talksasa Cloud') }}. All rights reserved.</p>
            <p style="margin: 5px 0;">This is an automated email. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
