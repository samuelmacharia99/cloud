<x-mail::message>
# New Server Order — Action Required

A new {{ ucfirst(str_replace('_', ' ', $service->product->type)) }} order has been placed and payment confirmed.

## Order Details

**Customer:** {{ $service->user->name }}
**Email:** {{ $service->user->email }}
**Phone:** {{ $service->user->phone ?? 'Not provided' }}

**Service:** {{ $service->product->name }}
**Billing Cycle:** {{ ucfirst($service->billing_cycle) }}
**Amount Paid:** {{ $service->invoice->currency_code }} {{ number_format($service->invoice->total, 2) }}

**Service ID:** #{{ $service->id }}

## Auto-Generated Credentials

These credentials have been automatically generated for this service:

<x-mail::panel>
**Username:** `root`

**Password:** `{{ json_decode($service->credentials)->password }}`
</x-mail::panel>

## Action Required

Please complete the following steps to activate this service for the customer:

1. **Configure the server** with the auto-generated credentials
2. **Set the hostname/IP** in your infrastructure
3. **Test connectivity** to ensure the server is accessible
4. **Update service details** in the admin panel if needed
5. **Notify the customer** if additional setup is required (typically credentials email is already sent)

## Admin Panel Link

<x-mail::button :url="route('admin.services.show', $service)">
View Service Details
</x-mail::button>

---

**Note:** The customer has already been sent a separate email with their login credentials and connection instructions.

<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
</x-mail::footer>
</x-mail::message>
