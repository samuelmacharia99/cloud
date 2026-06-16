<h2 style="margin:0 0 12px 0;">Payment not completed</h2>

<p>
    Your payment for invoice <strong>{{ $invoice->invoice_number }}</strong>
    (KES {{ number_format($invoice->total, 2) }}) could not be processed.
</p>

<p><strong>Details:</strong> {{ $reason }}</p>

<p>You can try again from your dashboard or choose a different payment method.</p>

<p>
    <a href="{{ url('/my/invoices/'.$invoice->id.'/pay') }}"
       style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
        Retry payment
    </a>
</p>

<p>Thanks,<br>{{ email_company_name() }}</p>
