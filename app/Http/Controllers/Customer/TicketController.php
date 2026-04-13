<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the user's tickets
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Ticket::class);

        $user = auth()->user();
        $query = $user->tickets()->with('assignee')->latest()->paginate(15);

        // If reseller, also show their customers' tickets
        if ($user->isReseller()) {
            $customerIds = \App\Models\Service::where('reseller_id', $user->id)
                ->distinct()
                ->pluck('user_id');

            $query = Ticket::with('user', 'assignee')
                ->where('user_id', $user->id)
                ->orWhereIn('user_id', $customerIds)
                ->latest()
                ->paginate(15);
        }

        return view('customer.tickets.index', compact('query'));
    }

    /**
     * Show a specific ticket
     */
    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load('user', 'replies.user', 'assignee');

        return view('customer.tickets.show', compact('ticket'));
    }

    /**
     * Show the form for creating a new ticket
     */
    public function create()
    {
        $this->authorize('create', Ticket::class);

        $priorityOptions = ['low', 'medium', 'high', 'urgent'];

        return view('customer.tickets.create', compact('priorityOptions'));
    }

    /**
     * Store a newly created ticket in storage
     */
    public function store(Request $request)
    {
        $this->authorize('create', Ticket::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $ticket = Ticket::create([
            'user_id' => auth()->id(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'status' => 'open',
        ]);

        // Send notification
        $this->notifyTicketCreated($ticket);

        return redirect()->route('customer.tickets.show', $ticket)->with('success', 'Ticket created successfully.');
    }

    /**
     * Add a reply to a ticket
     */
    public function reply(Request $request, Ticket $ticket)
    {
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'message' => $validated['message'],
            'is_staff_reply' => false,
        ]);

        // If closed ticket gets a reply from customer, reopen it
        if ($ticket->isClosed()) {
            $ticket->update(['status' => 'open', 'resolved_at' => null]);
        }

        // Send notification
        $this->notifyTicketReplied($ticket);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Reply added successfully.']);
        }

        return back()->with('success', 'Reply added successfully.');
    }

    /**
     * Close a ticket (customer only)
     */
    public function close(Request $request, Ticket $ticket)
    {
        $this->authorize('close', $ticket);

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Ticket closed successfully.']);
        }

        return back()->with('success', 'Ticket closed successfully.');
    }

    /**
     * Notify about new ticket creation
     */
    private function notifyTicketCreated(Ticket $ticket): void
    {
        $notificationService = new \App\Services\NotificationService(new \App\Services\SmsService());
        $notificationService->notifyTicketCreated($ticket);
    }

    /**
     * Notify about ticket reply
     */
    private function notifyTicketReplied(Ticket $ticket): void
    {
        $latestReply = $ticket->replies()->latest()->first();
        if ($latestReply) {
            $notificationService = new \App\Services\NotificationService(new \App\Services\SmsService());
            $notificationService->notifyTicketReplied($ticket, $latestReply);
        }
    }
}
