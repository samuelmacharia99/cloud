@extends('emails._layout')

@section('content')
<h1>Your hosting plan is almost full</h1>

<p>Hello {{ $service->user->name }},</p>

<div class="alert alert-warning">
    <strong>{{ $service->name }}</strong> has reached {{ number_format(collect($metricsAtRisk)->max(fn ($m) => $m['percent'] ?? 0), 0) }}% of one or more plan limits. Upgrade soon to avoid service interruption.
</div>

<h2>Usage summary</h2>
<table>
    @foreach ($metricsAtRisk as $metric => $entry)
        <tr>
            <td><strong>{{ ucfirst($metric === 'database' ? 'Databases' : ($metric === 'bandwidth' ? 'Bandwidth' : 'Storage')) }}:</strong></td>
            <td>
                @if (($entry['unit'] ?? '') === 'count')
                    {{ $entry['used'] }} / {{ $entry['limit'] }} ({{ $entry['percent'] }}%)
                @else
                    {{ number_format($entry['used'], 0) }} MB / {{ number_format($entry['limit'], 0) }} MB ({{ $entry['percent'] }}%)
                @endif
            </td>
        </tr>
    @endforeach
</table>

@if ($recommendedUpgrade)
    <p>We recommend upgrading to <strong>{{ $recommendedUpgrade->name }}</strong>.</p>
@endif

<p>
    <a href="{{ $upgradeUrl }}" class="cta-button">Upgrade your plan</a>
</p>

<p>After payment, your new limits are applied automatically — no downtime required.</p>

@include('emails.partials.signature')
@endsection
