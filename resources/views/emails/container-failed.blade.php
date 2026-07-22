<h2 style="margin:0 0 12px 0;">ALERT: Application Service Failed</h2>

<p>Your application service <strong>{{ $service->name }}</strong> has encountered a critical failure and is no longer running.</p>

<p><strong>Service Details:</strong></p>
<ul>
    <li>Service Name: {{ $service->name }}</li>
    <li>Service ID: {{ $service->id }}</li>
    <li>Status: Failed</li>
</ul>

<p><strong>Failure Reason:</strong><br>{{ $reason }}</p>

<p>Our system attempted to automatically restart your application, but it continues to fail. Please:</p>
<ol>
    <li>Review the application logs for error details</li>
    <li>Check your application configuration and recent deploys</li>
    <li>Verify that your application is starting correctly</li>
    <li>Contact support if you need assistance</li>
</ol>

<p>
    <a href="{{ url('/customer/services/' . $service->id) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View Service
    </a>
</p>

<p><strong>Important:</strong> Your service will remain offline until the issue is resolved.</p>

<p>Thanks,<br>{{ $siteName ?? email_company_name() }}</p>
