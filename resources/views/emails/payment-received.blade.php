@extends('emails._layout')

@section('content')
<h1>Payment Received</h1>

<p>Hello {{ $payment->invoice->user->name }},</p>

<div class="alert alert-success">
    <strong>Thank you! We have received your payment.</strong>
</div>

<h2>Payment Details</h2>
<table>
    <tr>
        <td><strong>Invoice Number:</strong></td>
        <td>{{ $payment->invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Amount Paid:</strong></td>
        <td><strong>Ksh {{ number_format($payment->amount, 0) }}</strong></td>
    </tr>
    <tr>
        <td><strong>Payment Method:</strong></td>
        <td>{{ $payment->payment_method?->label() ?? 'Unknown' }}</td>
    </tr>
    <tr>
        <td><strong>Payment Date:</strong></td>
        <td>{{ $payment->paid_at?->format('F d, Y H:i') ?? $payment->created_at->format('F d, Y H:i') }}</td>
    </tr>
    @if($payment->transaction_reference)
        <tr>
            <td><strong>Reference:</strong></td>
            <td>{{ $payment->transaction_reference }}</td>
        </tr>
    @endif
</table>

<p>
    <a href="{{ route('customer.invoices.show', $payment->invoice) }}" class="cta-button">View Invoice</a>
</p>

@php
    $remaining = $payment->invoice->getAmountRemaining();
@endphp

@if($remaining > 0)
    <div class="alert alert-info">
        <strong>Balance Remaining:</strong> Ksh {{ number_format($remaining, 0) }}
    </div>
@else
    <div class="alert alert-success">
        <strong>Invoice Fully Paid</strong> – Thank you for your business!
    </div>
@endif

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
