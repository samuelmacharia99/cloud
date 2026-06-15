@component('mail::message')
    # ALERT: Container Backup Failed

    A scheduled backup for service **{{ $service->name }}** has failed.

    **Affected Service:**
    - Service ID: {{ $service->id }}
    - Service Name: {{ $service->name }}
    - Customer: {{ $service->user->name }}

    **Error Details:**
    {{ $error }}

    Please investigate this issue and ensure backups are taken manually if necessary. Contact the customer if their data may be at risk.

    @component('mail::button', ['url' => url('/admin/services/' . $service->id)])
        View Service
    @endcomponent

    Thanks,<br>
    {{ $siteName ?? email_company_name() }}
@endcomponent
