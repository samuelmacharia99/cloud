<h2 style="margin:0 0 12px 0;">ALERT: Container Backup Failed</h2>

<p>A scheduled backup for service <strong>{{ $service->name }}</strong> has failed.</p>

<p><strong>Affected Service:</strong></p>
<ul>
    <li>Service ID: {{ $service->id }}</li>
    <li>Service Name: {{ $service->name }}</li>
    <li>Customer: {{ $service->user->name }}</li>
</ul>

<p><strong>Error Details:</strong><br>{{ $error }}</p>

<p>Please investigate this issue and ensure backups are taken manually if necessary. Contact the customer if their data may be at risk.</p>

<p>
    <a href="{{ url('/admin/services/' . $service->id) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View Service
    </a>
</p>

<p>Thanks,<br>{{ $siteName ?? email_company_name() }}</p>
