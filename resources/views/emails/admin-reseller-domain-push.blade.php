@extends('emails._layout')

@section('content')
<h2 style="margin-top:0;">Reseller domain order pushed</h2>
<p>Reseller <strong>{{ $reseller->name }}</strong> pushed domain <strong>{{ $order->domain_name }}{{ $order->extension }}</strong> for customer <strong>{{ $customer->name }}</strong>.</p>
<ul>
    <li>Wholesale: KES {{ number_format($order->wholesale_amount, 2) }}</li>
    <li>Years: {{ $order->years }}</li>
    <li>Status: {{ ucfirst($order->status) }}</li>
</ul>
<p>Process this order in the admin domain orders panel.</p>
@endsection
