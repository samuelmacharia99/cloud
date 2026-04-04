@extends('emails._layout')

@section('content')
<h1>Domain Expiry Notice</h1>

<p>Hello {{ $domain->user->name }},</p>

<div class="alert @if($daysUntilExpiry <= 7) alert-danger @else alert-warning @endif">
    <strong>
        @if($daysUntilExpiry <= 0)
            Your domain has EXPIRED!
        @elseif($daysUntilExpiry === 1)
            Your domain expires TOMORROW!
        @else
            Your domain expires in {{ $daysUntilExpiry }} days.
        @endif
    </strong>
</div>

<h2>Domain Information</h2>
<table>
    <tr>
        <td><strong>Domain:</strong></td>
        <td>{{ $domain->name }}</td>
    </tr>
    <tr>
        <td><strong>Expiry Date:</strong></td>
        <td>{{ $domain->expires_at->format('F d, Y') }}</td>
    </tr>
    <tr>
        <td><strong>Days Until Expiry:</strong></td>
        <td><strong>{{ max(0, $daysUntilExpiry) }} days</strong></td>
    </tr>
</table>

<p>Renewing your domain on time ensures uninterrupted service and protects your online presence. We offer convenient renewal options.</p>

<p>
    <a href="{{ route('customer.domains.show', $domain) }}" class="cta-button">Renew Domain Now</a>
</p>

<div class="alert alert-info">
    <strong>Tip:</strong> Consider enabling auto-renewal to avoid missing renewal deadlines in the future.
</div>

<p>If you have any questions about domain renewal, please contact our support team.</p>

<p>
    Best regards,<br>
    {{ \App\Models\Setting::getValue('mail_from_name', 'Talksasa Cloud') }}
</p>
@endsection
