<x-mail::message>
# New Reply to Your Support Ticket

Hello {{ $ticket->user->name }},

There is a new reply to your support ticket **#{{ $ticket->id }}**.

**Ticket:** {{ $ticket->title }}

**New Reply from {{ $reply->user->name }}:**
{{ $reply->message }}

**Ticket Status:** {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}

<x-mail::button :url="route('customer.tickets.show', $ticket)">
View Full Ticket
</x-mail::button>

You can reply to this ticket by logging into your account and visiting the Support Tickets section.

Thank you!

Best regards,<br>
{{ config('app.name') }} Support Team
</x-mail::message>
