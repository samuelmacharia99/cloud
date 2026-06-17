@extends('emails._layout')

@section('content')
<h1>Ticket escalated for priority review</h1>

<p>Hello {{ $ticket->user->name }},</p>

<p>Your support ticket has been escalated to our senior support team for additional assistance.</p>

<h2>Ticket #{{ $ticket->id }}</h2>
<p><strong>{{ $ticket->title }}</strong></p>

<p>{{ email_support_team_label() }} will review your request and respond as soon as possible. You can continue the conversation from your account.</p>

<p><a href="{{ route('customer.tickets.show', $ticket) }}" style="display: inline-block; padding: 10px 20px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 4px;">View ticket</a></p>

<p>Thank you for your patience.</p>
@endsection
