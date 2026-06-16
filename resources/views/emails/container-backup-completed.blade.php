<h2 style="margin:0 0 12px 0;">Container Backup Completed</h2>

<p>Your container backup for <strong>{{ $service->name }}</strong> has been completed successfully.</p>

<p><strong>Backup Details:</strong></p>
<ul>
    <li>Service: {{ $service->name }}</li>
    <li>Backup Name: {{ $backup->backup_name }}</li>
    <li>Backup Size: {{ formatBytes($backup->size_bytes) }}</li>
    <li>Completed at: {{ $backup->completed_at?->format('M d, Y H:i') ?? 'N/A' }}</li>
</ul>

<p>You can restore from this backup at any time through your dashboard.</p>

<p>
    <a href="{{ url('/customer/services/' . $service->id) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View Service
    </a>
</p>

<p>Thanks,<br>{{ $siteName ?? email_company_name() }}</p>
