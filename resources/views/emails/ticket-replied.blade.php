@extends('emails._layout')

@section('content')
<h1>New Reply to Your Support Ticket</h1>

<p>Hello {{ $ticket->user->name }},</p>

<p>There is a new reply to your support ticket #{{ $ticket->id }}.</p>

<h2>Ticket: {{ $ticket->title }}</h2>
<p><strong>Ticket Status:</strong> {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}</p>

<h2>Reply from {{ $reply->user->name }}</h2>
<p style="white-space: pre-wrap; word-wrap: break-word;">{{ $reply->message }}</p>

<p style="color: #666; font-size: 12px; margin-top: 20px;">
    <em>Replied on: {{ $reply->created_at->format('F d, Y H:i') }}</em>
</p>

<p>You can view the full ticket conversation and reply by logging into your account and visiting the Support Tickets section.</p>

<p><a href="{{ route('customer.tickets.show', $ticket) }}" style="display: inline-block; padding: 10px 20px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 4px; margin-top: 10px;">View Full Ticket</a></p>

<p>Thank you!<br>
{{ config('app.name') }} Support Team</p>
@endsection
