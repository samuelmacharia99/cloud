<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = request()->user();
        $tickets = $user->isAdmin()
            ? Ticket::with('user', 'assignee')->latest()->paginate(20)
            : $user->tickets()->latest()->paginate(20);

        return view('tickets.index', compact('tickets'));
    }

    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        return view('tickets.show', compact('ticket'));
    }

    public function create()
    {
        return view('tickets.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['status'] = 'open';

        Ticket::create($validated);

        return redirect()->route('tickets.index')
            ->with('success', 'Ticket created successfully.');
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $validated['ticket_id'] = $ticket->id;
        $validated['user_id'] = $request->user()->id;
        $validated['is_staff_reply'] = $request->user()->is_admin;

        TicketReply::create($validated);

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Reply added successfully.');
    }

    public function close(Ticket $ticket)
    {
        $this->authorize('close', $ticket);

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
        ]);

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Ticket closed.');
    }
}
