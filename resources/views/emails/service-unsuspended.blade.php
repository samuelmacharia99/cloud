@extends('emails._layout')

@section('content')
<h1>Service Restored</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-success">
    <strong>Your service has been successfully restored!</strong>
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
        <td><strong>Restored:</strong></td>
        <td>{{ now()->format('F d, Y H:i') }}</td>
    </tr>
</table>

<div class="alert alert-info">
    <strong>Good News:</strong> Your invoice has been marked as paid and your service is now active again.
</div>

<p>You can now access and use your service normally. If you experience any issues, please contact our support team.</p>

@include('emails.partials.signature')
@endsection
