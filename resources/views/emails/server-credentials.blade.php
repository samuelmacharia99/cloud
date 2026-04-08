<x-mail::message>
# Your {{ $service->product->name }} is Ready

Hello {{ $service->user->name }},

Your {{ ucfirst(str_replace('_', ' ', $service->product->type)) }} has been provisioned and is ready to use. Your login credentials are provided below.

<x-mail::panel>
**Login Information:**

**Username:** `root`

**Password:** `{{ json_decode($service->credentials)->password }}`
</x-mail::panel>

## Important Security Information

Please take the following steps immediately:

1. **Change your root password** after your first login
2. **Never share your credentials** with anyone
3. **Keep your password secure** — store it in a secure password manager
4. **Disable root SSH login** and create a regular user account for daily use (optional but recommended)

## What's Next?

You can now connect to your server using an SSH client:

```
ssh root@<your-server-ip>
```

If you need assistance or have any questions, please contact our support team.

---

<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
</x-mail::footer>
</x-mail::message>
