@extends('emails._layout')

@section('content')
<h1>Verify Your Email Address</h1>

<p>Hello {{ $user->name }},</p>

<p>Thank you for registering with {{ email_company_name() }}. To complete your account setup and gain full access to your dashboard, please enter the verification code below.</p>

<div style="background-color: #f3f4f6; border: 2px solid #e5e7eb; border-radius: 8px; padding: 30px; margin: 30px 0; text-align: center;">
    <p style="color: #6b7280; font-size: 14px; margin: 0 0 20px 0;">Your verification code is:</p>
    <div style="font-size: 48px; font-weight: bold; letter-spacing: 0.3em; color: #1f2937; font-family: 'Courier New', monospace; line-height: 1.4;">
        {{ $code }}
    </div>
</div>

<p style="text-align: center; color: #6b7280; font-size: 14px;">
    <strong>This code expires in 15 minutes.</strong>
</p>

<p>Enter this code on the verification page to complete your registration. If you did not create an account with us, you can safely ignore this email.</p>

<div class="alert alert-info">
    <strong>Need help?</strong> If you have any questions, please contact our support team at <a href="mailto:{{ email_support_email() }}">support</a>.
</div>
@endsection
