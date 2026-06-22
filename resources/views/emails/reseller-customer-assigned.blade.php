@extends('emails._layout')

@section('content')
<h1>New customer assigned</h1>

<p>Hello {{ $reseller->name }},</p>

<p>A customer account has been assigned to your reseller portal:</p>

<table cellpadding="0" cellspacing="0">
    <tr>
        <td><strong>Name</strong></td>
        <td>{{ $customer->name }}</td>
    </tr>
    <tr>
        <td><strong>Email</strong></td>
        <td>{{ $customer->email }}</td>
    </tr>
    @if($customer->company)
    <tr>
        <td><strong>Company</strong></td>
        <td>{{ $customer->company }}</td>
    </tr>
    @endif
    <tr>
        <td><strong>Previously managed by</strong></td>
        <td>{{ $summary['from_label'] }}</td>
    </tr>
</table>

<h2>Resources</h2>
<ul>
    <li>{{ $summary['services'] }} service(s)</li>
    <li>{{ $summary['domains'] }} domain(s)</li>
    @if($summary['cancelled_invoices'] > 0)
        <li>{{ $summary['cancelled_invoices'] }} open invoice(s) cancelled — set up fresh billing with this customer in your portal</li>
    @endif
</ul>

<p>Sign in to your reseller dashboard to view their services, create invoices, and manage their account.</p>

@include('emails.partials.signature')
@endsection
