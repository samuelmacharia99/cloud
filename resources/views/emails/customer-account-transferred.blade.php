@extends('emails._layout')

@section('content')
<h1>Your account has been updated</h1>

<p>Hello {{ $user->name }},</p>

<p>We're writing to let you know your {{ email_company_name() }} account has been updated. Your hosting, domains, and login details remain the same — everything stays active under your existing account.</p>

<div class="alert alert-info">
    <strong>Billing</strong><br>
    Any previous outstanding invoices on your account have been cleared. For renewals and new orders going forward, please sign in to your client portal or contact our support team — we're happy to help you get set up.
</div>

<p>
    <a href="{{ $portalUrl }}" class="cta-button">Go to your client portal</a>
</p>

<p>If you have questions about your services or need assistance, reach us at
    @if(email_support_email())
        <a href="mailto:{{ email_support_email() }}">{{ email_support_email() }}</a>
    @else
        our support team
    @endif
    @if(!empty(email_branding()['support_phone']))
        or {{ email_branding()['support_phone'] }}
    @endif
    .
</p>

@include('emails.partials.signature')
@endsection
