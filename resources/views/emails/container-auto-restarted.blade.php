<h2 style="margin:0 0 12px 0;">Container Auto-Restarted</h2>

<p>Your container service <strong>{{ $service->name }}</strong> experienced a temporary failure but was automatically restarted by our monitoring system.</p>

<p><strong>Service Details:</strong></p>
<ul>
    <li>Service Name: {{ $service->name }}</li>
    <li>Service ID: {{ $service->id }}</li>
    <li>Restart Attempts: {{ $attemptCount }}</li>
    <li>Status: Recovered</li>
</ul>

<p>Your service is now running normally. No action is required from your side.</p>

<p><strong>What happened:</strong> our monitoring detected the container had stopped, then recovered it after {{ $attemptCount }} attempt(s).</p>

<p>
    <a href="{{ url('/customer/services/' . $service->id) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View Service
    </a>
</p>

<p>Thanks,<br>{{ $siteName ?? email_company_name() }}</p>
