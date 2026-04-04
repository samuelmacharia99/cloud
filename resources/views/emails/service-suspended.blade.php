@extends('emails._layout')

@section('content')
<h1>Service Suspended</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-danger">
    <strong>Your service has been suspended due to an overdue payment.</strong>
</div>

<h2>Service Details</h2>
<table>
    <tr>
        <td><strong>Service Name:</strong></td>
        <td>{{ $service->name }}</td>
    </tr>
    <tr>
        <td><strong>Type:</strong></td>
        <td>{{ $service->product->name ?? 'N/A' }}</td>
    </tr>
    <tr>
        <td><strong>Suspended:</strong></td>
        <td>{{ now()->format('F d, Y H:i') }}</td>
    </tr>
</table>

<div class="alert alert-warning">
    <strong>Action Required:</strong> Please settle the outstanding payment to restore your service.
</div>

@if($service->invoice)
    <p>
        <a href="{{ route('customer.invoices.show', $service->invoice) }}" class="cta-button">View Outstanding Invoice</a>
    </p>
@endif

<p>Once payment is received and processed, your service will be automatically restored. This typically takes a few minutes.</p>

<p>If you believe this is an error or have already made a payment, please contact our support team immediately.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
