@extends('emails._layout')

@section('content')
<h1>Plan upgraded successfully</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-success">
    <strong>{{ $service->name }}</strong> has been upgraded from {{ $previousProduct->name }} to {{ $newProduct->name }}.
</div>

<h2>New plan</h2>
<table>
    <tr>
        <td><strong>Service:</strong></td>
        <td>{{ $service->name }}</td>
    </tr>
    <tr>
        <td><strong>Previous plan:</strong></td>
        <td>{{ $previousProduct->name }}</td>
    </tr>
    <tr>
        <td><strong>New plan:</strong></td>
        <td>{{ $newProduct->name }}</td>
    </tr>
</table>

<p>Your updated storage, bandwidth, and database limits are now active on the server.</p>

<p>
    <a href="{{ $serviceUrl }}" class="cta-button">View service</a>
</p>

@include('emails.partials.signature')
@endsection
