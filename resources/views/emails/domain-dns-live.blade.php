@extends('emails._layout')

@section('content')
<h1>DNS is live</h1>

<p>Hello {{ $domain->user->name }},</p>

<div class="alert alert-success">
    <strong>{{ $domain->fqdn() }} is now answering DNS queries.</strong>
</div>

<p>
    The nameservers for this domain have been picked up at the registry, so the records on it are
    being served worldwide. Changes you make from now on take effect within a minute or two.
</p>

<h2>Domain Information</h2>
<table>
    <tr>
        <td><strong>Domain:</strong></td>
        <td>{{ $domain->fqdn() }}</td>
    </tr>
    <tr>
        <td><strong>Nameservers:</strong></td>
        <td>{{ $domain->nameserver_1 ?? '—' }}@if($domain->nameserver_2), {{ $domain->nameserver_2 }}@endif</td>
    </tr>
</table>

<p style="margin-top:24px;">
    <a href="{{ $dnsUrl }}" class="button">Manage DNS records</a>
</p>
@endsection
