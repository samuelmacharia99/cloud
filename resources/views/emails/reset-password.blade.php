@extends('emails._layout')

@section('content')
<h1>Reset Your Password</h1>

<p>We received a request to reset the password associated with your account. Click the button below to create a new password.</p>

<center>
    <a href="{{ $url }}" class="cta-button">Reset Password</a>
</center>

<p style="text-align: center; margin-top: 30px; font-size: 12px; color: #6b7280;">
    <strong>This link expires in 60 minutes.</strong>
</p>

<div class="alert alert-info">
    <strong>Security Tip:</strong> If you did not request a password reset, no action is required. Your account remains secure and your password will not change.
</div>
@endsection
