@extends('emails._layout')

@section('content')
<h1>Manual Payment Rejected</h1>

<p>Hello {{ $payment->user->name }},</p>

<p>Unfortunately, your manual payment submission for invoice <strong>{{ $payment->invoice->invoice_number }}</strong> has been rejected.</p>

<div class="alert alert-warning">
    <strong>Reason:</strong> {{ $rejectionReason }}
</div>

<p><strong>Amount:</strong> Ksh {{ number_format($payment->amount, 2) }}</p>

<p>Please contact support if you have questions, or submit your payment again with the correct details.</p>

<center>
    <a href="{{ $invoiceUrl }}" class="cta-button">View Invoice</a>
</center>
@endsection
