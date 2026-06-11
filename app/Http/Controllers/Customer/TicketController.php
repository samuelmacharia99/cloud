<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\NotificationService;
use App\Services\ResellerScopeService;
use App\Services\TicketAttachmentService;
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

        if ($user->isReseller()) {
            $customerIds = app(ResellerScopeService::class)->managedCustomerIds($user);

            $query = Ticket::with('user', 'assignee', 'replies')
                ->where(function ($builder) use ($user, $customerIds) {
                    $builder->where('user_id', $user->id)
                        ->orWhereIn('user_id', $customerIds);
                });
        } else {
            $query = $user->tickets()->with('assignee', 'replies');
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tickets = $query->latest()->paginate(15)->withQueryString();

        return view('customer.tickets.index', compact('tickets'));
    }

    /**
     * Show a specific ticket
     */
    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load('user', 'replies.user', 'replies.attachments', 'assignee', 'attachments');

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

        $validated = $request->validate(array_merge([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
        ], TicketAttachmentService::validationRules()));

        $ticket = Ticket::create([
            'user_id' => auth()->id(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'status' => 'open',
        ]);

        app(TicketAttachmentService::class)->storeForTicket(
            $ticket,
            $request->user(),
            $request->file('attachments', [])
        );

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

        $validated = $request->validate(array_merge([
            'message' => 'required|string|max:5000',
        ], TicketAttachmentService::validationRules()));

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'message' => $validated['message'],
            'is_staff_reply' => false,
        ]);

        app(TicketAttachmentService::class)->storeForReply(
            $ticket,
            $reply,
            $request->user(),
            $request->file('attachments', [])
        );

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
        app(NotificationService::class)->notifyTicketCreated($ticket);
    }

    /**
     * Notify about ticket reply
     */
    private function notifyTicketReplied(Ticket $ticket): void
    {
        $latestReply = $ticket->replies()->latest()->first();
        if ($latestReply) {
            app(NotificationService::class)->notifyTicketReplied($ticket, $latestReply);
        }
    }
}
