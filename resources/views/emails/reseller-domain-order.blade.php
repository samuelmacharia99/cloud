@extends('emails._layout')

@section('content')
@php
    $domain = $order->domain_name . $order->extension;
@endphp

@if($variant === 'pushed')
    <h2 style="margin-top:0;">Domain pushed to admin</h2>
    <p>Domain <strong>{{ $domain }}</strong> for customer <strong>{{ $customer->name }}</strong> has been pushed to admin for registration.</p>
    <p>Wholesale amount debited: <strong>KES {{ number_format($order->wholesale_amount, 2) }}</strong></p>
@elseif($variant === 'queued')
    <h2 style="margin-top:0;">Domain order waiting for wallet top-up</h2>
    <p>Customer <strong>{{ $customer->name }}</strong> paid for <strong>{{ $domain }}</strong>, but your wallet balance is insufficient.</p>
    <p>Required wholesale amount: <strong>KES {{ number_format($order->wholesale_amount, 2) }}</strong></p>
    <p><a href="{{ route('reseller.wallet.index') }}">Top up your wallet</a> to push this order automatically.</p>
@elseif($variant === 'completed')
    <h2 style="margin-top:0;">Domain registration complete</h2>
    <p>Domain <strong>{{ $domain }}</strong> for customer <strong>{{ $customer->name }}</strong> has been registered successfully.</p>
@else
    <h2 style="margin-top:0;">Domain registration failed</h2>
    <p>Domain <strong>{{ $domain }}</strong> could not be registered.</p>
    @if($order->failure_reason)
        <p>Reason: {{ $order->failure_reason }}</p>
    @endif
@endif
@endsection
