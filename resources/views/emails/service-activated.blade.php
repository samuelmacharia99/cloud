@extends('emails._layout')

@section('content')
<h1>Service Activated</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-success">
    <strong>Your service is now active and ready to use!</strong>
</div>

<h2>Service Information</h2>
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
        <td><strong>Activated:</strong></td>
        <td>{{ now()->format('F d, Y H:i') }}</td>
    </tr>
    @if($service->next_due_date)
        <tr>
            <td><strong>Next Renewal:</strong></td>
            <td>{{ $service->next_due_date->format('F d, Y') }}</td>
        </tr>
    @endif
</table>

@if($service->credentials || $service->getHostingCredentials())
    <h2>Service Credentials</h2>
    @php($hostingCredentials = $service->getHostingCredentials())
    @if($hostingCredentials)
        <p>Your shared hosting control panel login details:</p>
        <table>
            @if(!empty($hostingCredentials['domain']))
                <tr>
                    <td><strong>Domain:</strong></td>
                    <td>{{ $hostingCredentials['domain'] }}</td>
                </tr>
            @endif
            <tr>
                <td><strong>Username:</strong></td>
                <td><code>{{ $hostingCredentials['username'] }}</code></td>
            </tr>
            <tr>
                <td><strong>Password:</strong></td>
                <td><code>{{ $hostingCredentials['password'] }}</code></td>
            </tr>
            @if(!empty($hostingCredentials['panel_url']))
                <tr>
                    <td><strong>Control Panel:</strong></td>
                    <td><a href="{{ $hostingCredentials['panel_url'] }}">{{ $hostingCredentials['panel_url'] }}</a></td>
                </tr>
            @endif
        </table>
    @else
        <p>Your service credentials have been set up. You can access them from your dashboard:</p>
        <div class="alert alert-info">
            {{ $service->credentials }}
        </div>
    @endif
@endif

<p>
    <a href="{{ route('customer.services.show', $service) }}" class="cta-button">View Service</a>
</p>

<p>If you need any assistance with your service, please don't hesitate to contact our support team.</p>

@include('emails.partials.signature')
@endsection
