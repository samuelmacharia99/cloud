@extends('emails._layout')

@section('content')
<h1>Payment Received ✓</h1>

<p>Hello {{ $payment->invoice->user->name }},</p>

<div class="alert alert-success">
    <strong>Thank you! We have successfully received your payment.</strong> Your invoice payment has been processed and recorded in your account.
</div>

<h2>Payment Receipt</h2>
<table>
    <tr>
        <td><strong>Invoice Number:</strong></td>
        <td>{{ $payment->invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Amount Paid:</strong></td>
        <td style="font-size: 16px; color: #22c55e;"><strong>Ksh {{ number_format($payment->amount, 2) }}</strong></td>
    </tr>
    <tr>
        <td><strong>Payment Method:</strong></td>
        <td>
            @if($payment->payment_method)
                <span style="display: inline-block; padding: 4px 12px; background-color: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 12px; font-weight: bold;">
                    {{ $payment->payment_method->label() }}
                </span>
            @else
                Unknown
            @endif
        </td>
    </tr>
    <tr>
        <td><strong>Payment Date:</strong></td>
        <td>{{ $payment->paid_at?->format('F d, Y \\a\\t H:i') ?? $payment->created_at->format('F d, Y \\a\\t H:i') }}</td>
    </tr>
    @if($payment->transaction_reference)
        <tr>
            <td><strong>Transaction Reference:</strong></td>
            <td style="font-family: monospace; font-size: 12px; color: #6b7280;">{{ $payment->transaction_reference }}</td>
        </tr>
    @endif
</table>

<h2>Invoice Summary</h2>
<table>
    <tr>
        <td><strong>Invoice Total:</strong></td>
        <td style="text-align: right;">Ksh {{ number_format($payment->invoice->total, 2) }}</td>
    </tr>
    <tr>
        <td><strong>Amount Paid:</strong></td>
        <td style="text-align: right;">Ksh {{ number_format($payment->amount, 2) }}</td>
    </tr>
    @php
        $remaining = $payment->invoice->getAmountRemaining();
    @endphp
    <tr style="border-top: 2px solid #e5e7eb;">
        <td><strong>Balance Remaining:</strong></td>
        <td style="text-align: right; padding-top: 10px;">
            @if($remaining > 0)
                <strong style="color: #f59e0b;">Ksh {{ number_format($remaining, 2) }}</strong>
            @else
                <strong style="color: #22c55e;">Paid in Full</strong>
            @endif
        </td>
    </tr>
</table>

@if($remaining > 0)
    <div class="alert alert-warning">
        <strong>Outstanding Balance:</strong> You still have Ksh {{ number_format($remaining, 2) }} remaining on this invoice.
        <a href="{{ route('customer.invoices.show', $payment->invoice) }}" style="color: #f59e0b; text-decoration: none; font-weight: bold;">Pay now →</a>
    </div>
@else
    <div class="alert alert-success">
        <strong>✓ Invoice Fully Paid</strong> – Thank you for your payment! Your invoice is now fully settled.
    </div>

    @if($payment->invoice->items()->whereNotNull('service_id')->exists())
        <h2>Your Services</h2>
        <p>Your services have been activated and are now ready to use. You can access them anytime from your dashboard.</p>
        <p style="text-align: center; margin: 20px 0;">
            <a href="{{ route('customer.services.index') }}" class="cta-button">View Your Services</a>
        </p>
    @endif
@endif

<h2>What's Next?</h2>
@if($remaining <= 0)
    <p>Your invoice is now fully paid. Here's what happens next:</p>
    <ul style="margin: 15px 0; padding-left: 20px;">
        <li><strong>Services Activated:</strong> Your services are now live and ready to use</li>
        <li><strong>Access Your Dashboard:</strong> Log in to manage your services and settings</li>
        <li><strong>Support Available:</strong> Our team is available 24/7 if you need help</li>
    </ul>
@else
    <p>To complete payment for this invoice, please visit:</p>
    <p style="text-align: center; margin: 20px 0;">
        <a href="{{ route('customer.invoices.show', $payment->invoice) }}" class="cta-button">View Invoice & Pay</a>
    </p>
@endif

<div class="alert alert-info" style="margin-top: 30px;">
    <strong>Keep this email for your records.</strong> It serves as your payment receipt. You can also download a copy of your invoice from your account.
</div>

<p style="margin-top: 30px;">
    Best regards,<br>
    <strong>{{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}</strong><br>
    Support Team
</p>
@endsection
