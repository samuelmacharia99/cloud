@extends('emails._layout')

@section('content')
<h1>Domain renewed</h1>

<p>Hello {{ $recipient->name }},</p>

<p>
    <strong>{{ $fqdn }}</strong> has been renewed for
    {{ $years }} year{{ $years > 1 ? 's' : '' }}.
</p>

@if($endCustomerName)
    <p>This domain is managed for your customer <strong>{{ $endCustomerName }}</strong>.</p>
@endif

<table style="width:100%; border-collapse: collapse; margin: 20px 0;">
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">New expiry date</td>
        <td style="padding: 8px 0; text-align: right; font-weight: 600;">{{ $newExpiry->format('F d, Y') }}</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Renewal order</td>
        <td style="padding: 8px 0; text-align: right; font-weight: 600;">#{{ $renewalOrder->id }}</td>
    </tr>
</table>

<p>The updated expiry is now reflected in your domain management area.</p>

<p>If you have any questions, please contact support.</p>
@endsection
