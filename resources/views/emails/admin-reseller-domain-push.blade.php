@extends('emails._layout')

@section('content')
@if($order->isPlatformOrder())
<h2 style="margin-top:0;">Platform domain order ready for registrar</h2>
<p>Customer <strong>{{ $customer->name }}</strong> paid for domain <strong>{{ $order->fullDomainName() }}</strong> directly on the platform.</p>
<ul>
    <li>Amount: KES {{ number_format($order->displayAmount(), 2) }}</li>
    <li>Years: {{ $order->years }}</li>
    <li>Status: {{ $order->statusDisplayLabel() }}</li>
</ul>
<p>Submit this order at the registrar from the admin domain orders panel.</p>
@else
<h2 style="margin-top:0;">Reseller domain order pushed</h2>
<p>Reseller <strong>{{ $reseller->name }}</strong> pushed domain <strong>{{ $order->fullDomainName() }}</strong> for customer <strong>{{ $customer->name }}</strong>.</p>
<ul>
    <li>Wholesale: KES {{ number_format($order->wholesale_amount, 2) }}</li>
    <li>Years: {{ $order->years }}</li>
    <li>Status: {{ ucfirst($order->status) }}</li>
</ul>
<p>Process this order in the admin domain orders panel.</p>
@endif
@endsection
