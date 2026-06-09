@component('mail::message')
# Service setup could not be completed

We received your payment, but automatic setup for **{{ $service->name }}** did not finish.

**Reason:** {{ $reason }}

Our team has been notified. You can also open a support ticket from your dashboard if you need help.

@component('mail::button', ['url' => url('/my/services/'.$service->id)])
View service
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
