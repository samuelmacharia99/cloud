@extends('emails._layout')

@section('content')
<h1>Domain Transfer Initiated</h1>

<p>Hello {{ $domain->user->name }},</p>

<div class="alert alert-info">
    <strong>✓ We've received your domain transfer request!</strong> Your transfer for <strong>{{ $fullDomain }}</strong> is now in progress.
</div>

<h2>What's Next?</h2>
<p>To complete the transfer, you need to authorize it with your current registrar:</p>

<table>
    <tr>
        <td><strong>Domain:</strong></td>
        <td>{{ $fullDomain }}</td>
    </tr>
    <tr>
        <td><strong>Current Registrar:</strong></td>
        <td>{{ $domain->old_registrar }}</td>
    </tr>
    <tr>
        <td><strong>Transfer Status:</strong></td>
        <td>
            <span style="display: inline-block; padding: 4px 12px; background-color: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 12px; font-weight: bold;">
                {{ $domain->getTransferStatusLabel() }}
            </span>
        </td>
    </tr>
</table>

<h2>Action Items</h2>
<ol style="margin: 15px 0; padding-left: 20px;">
    <li>
        <strong>Log in to your registrar account</strong><br>
        Go to {{ $domain->old_registrar }}
        @if($domain->old_registrar_url)
            (<a href="{{ $domain->old_registrar_url }}">{{ $domain->old_registrar_url }}</a>)
        @endif
    </li>
    <li>
        <strong>Find your domain's authorization code</strong><br>
        Look for "EPP Code", "Authorization Code", or "Transfer Code" in your domain settings
    </li>
    <li>
        <strong>Authorize the transfer</strong><br>
        You've already provided the EPP code to us, now authorize with your current registrar
    </li>
    <li>
        <strong>Wait for authorization</strong><br>
        This typically takes 3-5 business days. We'll notify you when the transfer completes.
    </li>
</ol>

<h2>Transfer Timeline</h2>
<table>
    <tr>
        <td><strong>Transfer Initiated:</strong></td>
        <td>{{ $domain->transfer_initiated_at ? $domain->transfer_initiated_at->format('F d, Y H:i') : 'Pending' }}</td>
    </tr>
    <tr>
        <td><strong>Estimated Completion:</strong></td>
        <td>5 business days from initiation</td>
    </tr>
</table>

<div class="alert alert-warning">
    <strong>⚠ Important:</strong> Do not renew your domain with your current registrar during the transfer process. This can cancel the transfer.
</div>

<h2>Questions?</h2>
<p>If you need any help with your domain transfer, our support team is available 24/7.</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{ route('customer.domains.index') }}" class="cta-button">View My Domains</a>
</p>

<p>
    Best regards,<br>
    <strong>{{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}</strong><br>
    Domain Management Team
</p>
@endsection
