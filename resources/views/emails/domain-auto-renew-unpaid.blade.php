@extends('emails._layout')

@section('content')
<h1>Auto-renew could not pay {{ $domain->fqdn() }}</h1>

<p>Hello {{ $invoice->user->name }},</p>

<div class="alert alert-warning">
    <strong>Auto-renew is on</strong> for <strong>{{ $domain->fqdn() }}</strong>, but there was not enough {{ $prepaidLabel }} to pay invoice {{ $invoice->invoice_number }}.
</div>

<p>Top up at least <strong>Ksh {{ number_format($amountDue, 2) }}</strong> so we can charge the renewal automatically. We will retry after you add funds, and every 30 minutes until the invoice is paid.</p>

<h2>What you need</h2>
<table>
    <tr>
        <td><strong>Domain:</strong></td>
        <td style="font-family: monospace;">{{ $domain->fqdn() }}</td>
    </tr>
    <tr>
        <td><strong>Invoice:</strong></td>
        <td style="font-family: monospace; font-weight: bold;">{{ $invoice->invoice_number }}</td>
    </tr>
    <tr>
        <td><strong>Amount due:</strong></td>
        <td style="font-size: 18px;"><strong>Ksh {{ number_format($amountDue, 2) }}</strong></td>
    </tr>
    @if($domain->expires_at)
        <tr>
            <td><strong>Domain expires:</strong></td>
            <td>{{ $domain->expires_at->format('F d, Y') }}</td>
        </tr>
    @endif
    @if($invoice->due_date)
        <tr>
            <td><strong>Invoice due:</strong></td>
            <td>{{ $invoice->due_date->format('F d, Y') }}</td>
        </tr>
    @endif
</table>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{ $topupUrl }}" class="cta-button">Top up {{ $prepaidLabel }}</a>
</p>

<p style="text-align: center;">
    <a href="{{ $invoiceUrl }}">View invoice {{ $invoice->invoice_number }}</a>
</p>

<p>If you no longer want this domain renewed automatically, turn auto-renew off from your domains page.</p>

@include('emails.partials.signature', ['supportLine' => 'Support Team'])
@endsection
