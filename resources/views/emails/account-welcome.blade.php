@extends('emails._layout')

@section('content')
@php
    $portalLabel = $accountType === 'reseller' ? 'reseller portal' : 'customer portal';
@endphp
<h1>Welcome to {{ email_company_name() }}</h1>

<p>Hello {{ $user->name }},</p>

<p>Your {{ $portalLabel }} account has been created. Use the login details below to sign in.</p>

<h2>Login Details</h2>
<table cellpadding="0" cellspacing="0" style="width:100%;margin:16px 0;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
    <tr>
        <td style="padding:12px 16px;font-weight:bold;width:140px;">Login URL</td>
        <td style="padding:12px 16px;"><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td>
    </tr>
    <tr>
        <td style="padding:12px 16px;font-weight:bold;border-top:1px solid #e5e7eb;">Email</td>
        <td style="padding:12px 16px;border-top:1px solid #e5e7eb;">{{ $user->email }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;font-weight:bold;border-top:1px solid #e5e7eb;">Password</td>
        <td style="padding:12px 16px;border-top:1px solid #e5e7eb;font-family:monospace;">{{ $plainPassword }}</td>
    </tr>
</table>

<p>
    <a href="{{ $loginUrl }}" class="cta-button">Sign In Now</a>
</p>

<h2>Security Tips</h2>
<ul>
    <li>Change your password after your first login</li>
    <li>Never share your login details with anyone</li>
    <li>Store your password in a secure password manager</li>
</ul>

<p>If you did not expect this account or need help, please contact our support team.</p>

@include('emails.partials.signature')
@endsection
