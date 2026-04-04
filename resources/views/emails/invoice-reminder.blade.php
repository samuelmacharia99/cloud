@extends('emails._layout')

@section('content')
<h1>Payment Reminder</h1>

<p>Hello {{ $invoice->user->name }},</p>

<div class="alert @if($daysBefore <= 1) alert-danger @else alert-warning @endif">
    <strong>
        @if($daysBefore <= 0)
            This invoice is due TODAY!
        @elseif($daysBefore === 1)
            This invoice is due TOMORROW!
        @else
            This invoice is due in {{ $daysBefore }} days.
        @endif
    </strong>
</div>

<p>We're sending this reminder that you have an outstanding invoice that needs payment.</p>

<h2>Invoice Summary</h2>
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
</table>

<p>
    <a href="{{ route('customer.invoices.show', $invoice) }}" class="cta-button">Pay Now</a>
</p>

<p>Paying on time helps us provide you with the best service. Thank you!</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
