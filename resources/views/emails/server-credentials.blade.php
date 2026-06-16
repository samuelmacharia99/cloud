<h2 style="margin:0 0 12px 0;">Your {{ $service->product->name }} is Ready</h2>

<p>Hello {{ $service->user->name }},</p>

<p>Your {{ ucfirst(str_replace('_', ' ', $service->product->type)) }} has been provisioned and is ready to use. Your login credentials are below.</p>

<p><strong>Login Information:</strong><br>
Username: <code>root</code><br>
Password: <code>{{ json_decode($service->credentials)->password }}</code></p>

<p><strong>Important:</strong> Please change your root password after first login and keep credentials secure.</p>

<p>You can connect via SSH:</p>
<p><code>ssh root@&lt;your-server-ip&gt;</code></p>

<p>If you need assistance, contact support.</p>
