@component('mail::message')
    # ALERT: Container Service Failed

    Your container service **{{ $service->name }}** has encountered a critical failure and is no longer running.

    **Service Details:**
    - Service Name: {{ $service->name }}
    - Service ID: {{ $service->id }}
    - Status: Failed

    **Failure Reason:**
    {{ $reason }}

    Our system has attempted to automatically restart your container, but it continues to fail. Please take the following actions:

    1. Review the container logs for error details
    2. Check your docker-compose configuration
    3. Verify that your application is starting correctly
    4. Contact support if you need assistance

    @component('mail::button', ['url' => url('/customer/services/' . $service->id)])
        View Service
    @endcomponent

    **Important:** Your service will remain offline until the issue is resolved.

    Thanks,<br>
    {{ $siteName ?? email_company_name() }}
@endcomponent
