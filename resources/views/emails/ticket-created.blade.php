<x-mail::message>
# New Support Ticket Created

Hello {{ $ticket->user->name }},

Your support ticket **#{{ $ticket->id }}** has been created successfully.

**Ticket Details:**
- **Title:** {{ $ticket->title }}
- **Priority:** {{ ucfirst($ticket->priority) }}
- **Status:** Open
- **Created:** {{ $ticket->created_at->format('M d, Y H:i') }}

**Description:**
{{ $ticket->description }}

Our support team will review your ticket and respond as soon as possible.

<x-mail::button :url="route('customer.tickets.show', $ticket)">
View Ticket
</x-mail::button>

You can also reply to this ticket by logging into your account and visiting the Support Tickets section.

Thank you for contacting us!

Best regards,<br>
{{ config('app.name') }} Support Team
</x-mail::message>
