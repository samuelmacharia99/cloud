@component('mail::message')
    # Container Backup Completed

    Your container backup for **{{ $service->name }}** has been completed successfully.

    **Backup Details:**
    - Service: {{ $service->name }}
    - Backup Name: {{ $backup->backup_name }}
    - Backup Size: {{ formatBytes($backup->size_bytes) }}
    - Completed at: {{ $backup->completed_at?->format('M d, Y H:i') ?? 'N/A' }}

    You can restore from this backup at any time through your dashboard.

    @component('mail::button', ['url' => url('/customer/services/' . $service->id)])
        View Service
    @endcomponent

    Thanks,<br>
    {{ $siteName ?? email_company_name() }}
@endcomponent
