@extends('emails._layout')

@section('content')
<h1>Support Ticket Created</h1>

<p>Hello {{ $ticket->user->name }},</p>

<p>Your support ticket has been created successfully. Our support team will review your ticket and respond as soon as possible.</p>

<h2>Ticket Details</h2>
<table>
    <tr>
        <td><strong>Ticket ID:</strong></td>
        <td>#{{ $ticket->id }}</td>
    </tr>
    <tr>
        <td><strong>Title:</strong></td>
        <td>{{ $ticket->title }}</td>
    </tr>
    <tr>
        <td><strong>Priority:</strong></td>
        <td>{{ ucfirst($ticket->priority) }}</td>
    </tr>
    <tr>
        <td><strong>Status:</strong></td>
        <td>Open</td>
    </tr>
    <tr>
        <td><strong>Created:</strong></td>
        <td>{{ $ticket->created_at->format('F d, Y H:i') }}</td>
    </tr>
</table>

<h2>Description</h2>
<p style="white-space: pre-wrap; word-wrap: break-word;">{{ $ticket->description }}</p>

<p>You can view your ticket and reply at any time by logging into your account and visiting the Support Tickets section.</p>

<p><a href="{{ route('customer.tickets.show', $ticket) }}" style="display: inline-block; padding: 10px 20px; background-color: #2563eb; color: white; text-decoration: none; border-radius: 4px; margin-top: 10px;">View Your Ticket</a></p>

<p>Thank you for contacting us!</p>

<p>Best regards,<br>
{{ config('app.name') }} Support Team</p>
@endsection
