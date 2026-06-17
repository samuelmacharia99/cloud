<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketAttachmentService;
use App\Services\TicketNotificationService;
use App\Services\TicketRoutingService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin');
    }

    /**
     * Display a listing of all tickets
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Ticket::class);

        $query = Ticket::with('user', 'assignee', 'reseller', 'escalatedByUser')
            ->visibleToAdmin()
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query = $query->paginate(15)->withQueryString();

        $statusOptions = ['open', 'in_progress', 'on_hold', 'closed'];
        $priorityOptions = ['low', 'medium', 'high', 'urgent'];
        $staffMembers = User::where('is_admin', true)->get();

        return view('admin.tickets.index', compact(
            'query',
            'statusOptions',
            'priorityOptions',
            'staffMembers'
        ));
    }

    /**
     * Show a specific ticket
     */
    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load('user', 'replies.user', 'replies.attachments', 'assignee', 'attachments', 'reseller', 'escalatedByUser');
        $staffMembers = User::where('is_admin', true)->get();

        return view('admin.tickets.show', compact('ticket', 'staffMembers'));
    }

    /**
     * Show the form for creating a new ticket
     */
    public function create()
    {
        $this->authorize('create', Ticket::class);

        $users = User::where('is_admin', false)->get();
        $priorityOptions = ['low', 'medium', 'high', 'urgent'];

        return view('admin.tickets.create', compact('users', 'priorityOptions'));
    }

    /**
     * Store a newly created ticket in storage
     */
    public function store(Request $request)
    {
        $this->authorize('create', Ticket::class);

        $validated = $request->validate(array_merge([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
        ], TicketAttachmentService::validationRules()));

        $owner = User::findOrFail($validated['user_id']);
        $routing = app(TicketRoutingService::class)->attributesForAdminCreator($owner);

        $ticket = Ticket::create([
            'user_id' => $validated['user_id'],
            ...$routing,
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

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket created successfully.');
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
            'is_staff_reply' => true,
        ]);

        app(TicketAttachmentService::class)->storeForReply(
            $ticket,
            $reply,
            $request->user(),
            $request->file('attachments', [])
        );

        // Auto-set status to in_progress if ticket is open
        if ($ticket->isOpen()) {
            $ticket->update(['status' => 'in_progress']);
        }

        // Send notification
        $this->notifyTicketReplied($ticket);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Reply added successfully.']);
        }

        return back()->with('success', 'Reply added successfully.');
    }

    /**
     * Update the ticket status
     */
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,on_hold,closed',
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'resolved_at' => $validated['status'] === 'closed' ? now() : null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Status updated successfully.']);
        }

        return back()->with('success', 'Status updated successfully.');
    }

    /**
     * Assign the ticket to a staff member
     */
    public function assign(Request $request, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $ticket->update(['assigned_to' => $validated['assigned_to']]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Ticket assigned successfully.']);
        }

        return back()->with('success', 'Ticket assigned successfully.');
    }

    /**
     * Delete a ticket
     */
    public function destroy(Request $request, Ticket $ticket)
    {
        $this->authorize('delete', $ticket);

        $ticket->replies()->delete();
        $ticket->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Ticket deleted successfully.']);
        }

        return redirect()->route('tickets.index')->with('success', 'Ticket deleted successfully.');
    }

    /**
     * Notify about new ticket creation
     */
    private function notifyTicketCreated(Ticket $ticket): void
    {
        app(TicketNotificationService::class)->notifyCreated($ticket);
    }

    private function notifyTicketReplied(Ticket $ticket): void
    {
        $latestReply = $ticket->replies()->latest()->first();
        if ($latestReply) {
            app(TicketNotificationService::class)->notifyReplied($ticket, $latestReply);
        }
    }
}
