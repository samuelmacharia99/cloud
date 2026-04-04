@extends('emails._layout')

@section('content')
<h1>Invoice Overdue</h1>

<p>Hello {{ $invoice->user->name }},</p>

<div class="alert alert-danger">
    <strong>URGENT: Your invoice is now OVERDUE.</strong>
</div>

<p>We have not received payment for the invoice listed below. Immediate action is required to avoid service suspension.</p>

<h2>Overdue Invoice</h2>
<table>
    <tr>
        <td><strong>Invoice Number:</strong></td>
        <td>{{ $invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Amount Due:</strong></td>
        <td><span class="highlight">Ksh {{ number_format($invoice->total, 0) }}</span></td>
    </tr>
    <tr>
        <td><strong>Due Date:</strong></td>
        <td>{{ $invoice->due_date->format('F d, Y') }}</td>
    </tr>
    <tr>
        <td><strong>Days Overdue:</strong></td>
        <td><strong>{{ now()->diffInDays($invoice->due_date) }} days</strong></td>
    </tr>
</table>

<div class="alert alert-warning">
    <strong>Important:</strong> Please submit payment immediately to avoid suspension of your services.
</div>

<p>
    <a href="{{ route('customer.invoices.show', $invoice) }}" class="cta-button">Submit Payment Now</a>
</p>

<p>If payment has already been made, please disregard this notice and contact our support team with proof of payment.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
