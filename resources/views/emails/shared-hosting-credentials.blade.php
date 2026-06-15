@extends('emails._layout')

@section('content')
<h1>Your Shared Hosting Is Ready</h1>

<p>Hello {{ $service->user->name }},</p>

<p>Your shared hosting account has been provisioned. Use the details below to log in to DirectAdmin.</p>

@if($credentials)
    <h2>Login Details</h2>
    <table>
        @if(!empty($credentials['domain']))
            <tr>
                <td><strong>Primary Domain:</strong></td>
                <td>{{ $credentials['domain'] }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Username:</strong></td>
            <td><code>{{ $credentials['username'] }}</code></td>
        </tr>
        <tr>
            <td><strong>Password:</strong></td>
            <td><code>{{ $credentials['password'] }}</code></td>
        </tr>
        @if(!empty($credentials['panel_url']))
            <tr>
                <td><strong>Control Panel:</strong></td>
                <td><a href="{{ $credentials['panel_url'] }}">{{ $credentials['panel_url'] }}</a></td>
            </tr>
        @endif
    </table>
@endif

<p>
    <a href="{{ route('customer.services.show', $service) }}" class="cta-button">View Service</a>
</p>

<p>Please change your password after your first login and keep these credentials secure.</p>

@include('emails.partials.signature')
@endsection
