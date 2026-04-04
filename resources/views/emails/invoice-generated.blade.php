@extends('emails._layout')

@section('content')
<h1>Invoice Generated</h1>

<p>Hello {{ $invoice->user->name }},</p>

<p>Your invoice has been generated and is ready for payment.</p>

<h2>Invoice Details</h2>
<table>
    <tr>
        <td><strong>Invoice Number:</strong></td>
        <td>{{ $invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Amount Due:</strong></td>
        <td>Ksh {{ number_format($invoice->total, 0) }}</td>
    </tr>
    <tr>
        <td><strong>Due Date:</strong></td>
        <td>{{ $invoice->due_date ? $invoice->due_date->format('F d, Y') : 'Upon receipt' }}</td>
    </tr>
</table>

<p>
    <a href="{{ route('customer.invoices.show', $invoice) }}" class="cta-button">View Invoice</a>
</p>

<p>You can pay this invoice using:</p>
<ul>
    @if(\App\Models\Setting::getValue('mpesa_enabled') === 'true')
        <li>M-Pesa mobile payment</li>
    @endif
    @if(\App\Models\Setting::getValue('bank_transfer_enabled') === 'true')
        <li>Bank transfer</li>
    @endif
    @if(\App\Models\Setting::getValue('manual_enabled') === 'true')
        <li>Manual payment recording</li>
    @endif
</ul>

<p>If you have any questions about this invoice, please contact our support team.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
