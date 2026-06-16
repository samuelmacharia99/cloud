<h2 style="margin:0 0 12px 0;">New Server Order - Action Required</h2>

<p>A new {{ ucfirst(str_replace('_', ' ', $service->product->type)) }} order has been placed and payment confirmed.</p>

<p><strong>Order Details:</strong></p>
<ul>
    <li>Customer: {{ $service->user->name }}</li>
    <li>Email: {{ $service->user->email }}</li>
    <li>Phone: {{ $service->user->phone ?? 'Not provided' }}</li>
    <li>Service: {{ $service->product->name }}</li>
    <li>Billing Cycle: {{ ucfirst($service->billing_cycle) }}</li>
    <li>Amount Paid: {{ $service->invoice->currency_code }} {{ number_format($service->invoice->total, 2) }}</li>
    <li>Service ID: #{{ $service->id }}</li>
</ul>

<p><strong>Auto-generated credentials:</strong></p>
<p>Username: <code>root</code><br>Password: <code>{{ json_decode($service->credentials)->password }}</code></p>

<p>
    <a href="{{ route('admin.services.show', $service) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View Service Details
    </a>
</p>

<p><strong>Note:</strong> The customer has already been sent a separate email with login credentials and connection instructions.</p>
