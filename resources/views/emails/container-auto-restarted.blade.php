@component('mail::message')
    # Container Auto-Restarted

    Your container service **{{ $service->name }}** experienced a temporary failure but was automatically restarted by our monitoring system.

    **Service Details:**
    - Service Name: {{ $service->name }}
    - Service ID: {{ $service->id }}
    - Restart Attempts: {{ $attemptCount }}
    - Status: Recovered ✓

    Your service is now running normally. No action is required from your side.

    **What Happened:**
    Our automated monitoring system detected that your container had stopped. After {{ $attemptCount }} attempt(s), the system successfully restarted your service and it is now operational.

    **If This Continues:**
    If you continue to receive these notifications frequently, please:
    1. Check your application logs for errors
    2. Review your docker-compose configuration
    3. Ensure your application has enough resources
    4. Contact support if you need assistance

    @component('mail::button', ['url' => url('/customer/services/' . $service->id)])
        View Service
    @endcomponent

    Thanks,<br>
    {{ config('app.name') }}
@endcomponent
