@extends('emails._layout')

@section('content')
<h1>Verify Your Email Address</h1>

<p>Thank you for registering with {{ \App\Models\Setting::getValue('company_name', 'Talksasa Cloud') }}. To complete your account setup and gain full access to your dashboard, please verify your email address by clicking the button below.</p>

<center>
    <a href="{{ $url }}" class="cta-button">Verify Email Address</a>
</center>

<p style="text-align: center; margin-top: 30px; font-size: 12px; color: #6b7280;">
    <strong>This link expires in 60 minutes.</strong>
</p>

<div class="alert alert-info">
    <strong>Security Tip:</strong> If you did not create an account with us, no action is required. You can safely ignore this email.
</div>
@endsection
