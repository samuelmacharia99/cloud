@component('mail::message')
# Payment not completed

Your payment for invoice **{{ $invoice->invoice_number }}** (KES {{ number_format($invoice->total, 2) }}) could not be processed.

**Details:** {{ $reason }}

You can try again from your dashboard or choose a different payment method.

@component('mail::button', ['url' => url('/my/invoices/'.$invoice->id.'/pay')])
Retry payment
@endcomponent

Thanks,<br>
{{ email_company_name() }}
@endcomponent
