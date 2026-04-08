@extends('emails._layout')

@section('content')
<h1>Password Changed Successfully</h1>

<p>Hello {{ $user->name }},</p>

<p>Your Talksasa Cloud password has been changed successfully.</p>

<h2>What Happened</h2>
<p>If you made this change, you can safely ignore this email. Your account is secure.</p>

<h2>Suspicious Activity?</h2>
<p>If you did <strong>NOT</strong> change your password, please take the following steps immediately:</p>
<ol>
    <li>Click the button below to reset your password</li>
    <li>Use a strong, unique password that you haven't used before</li>
    <li>Contact our support team if you notice any suspicious activity on your account</li>
</ol>

<p>
    <a href="{{ route('password.request') }}" class="cta-button">Reset Password Now</a>
</p>

<h2>Security Tips</h2>
<ul>
    <li>Use a strong password with at least 8 characters, including letters, numbers, and symbols</li>
    <li>Never share your password with anyone, including support staff</li>
    <li>Change your password regularly (every 3-6 months)</li>
    <li>Use a unique password for Talksasa Cloud that you don't use elsewhere</li>
</ul>

<p>If you have any questions or concerns about this change, please contact our support team.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
