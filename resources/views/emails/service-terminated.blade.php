@extends('emails._layout')

@section('content')
<h1>Service Terminated</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-danger">
    <strong>Your service has been terminated.</strong>
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
        <td><strong>Terminated:</strong></td>
        <td>{{ now()->format('F d, Y H:i') }}</td>
    </tr>
</table>

<div class="alert alert-info">
    <p><strong>Important:</strong> Any data associated with this service may no longer be accessible. Please ensure you have backed up any critical data.</p>
</div>

<p>Your service was terminated due to non-payment of invoices. If you wish to restore your service, please contact our support team to discuss payment options.</p>

<p>
    <a href="{{ route('customer.invoices.index') }}" class="cta-button">View Your Invoices</a>
</p>

<p>If you have any questions or believe this is an error, please reach out to us immediately.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
