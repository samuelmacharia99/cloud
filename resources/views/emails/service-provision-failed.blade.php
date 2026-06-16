<h2 style="margin:0 0 12px 0;">Service setup could not be completed</h2>

<p>We received your payment, but automatic setup for <strong>{{ $service->name }}</strong> did not finish.</p>

<p><strong>Reason:</strong> {{ $reason }}</p>

<p>Our team has been notified. You can also open a support ticket from your dashboard if you need help.</p>

<p>
    <a href="{{ url('/my/services/'.$service->id) }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        View service
    </a>
</p>

<p>Thanks,<br>{{ email_company_name() }}</p>
