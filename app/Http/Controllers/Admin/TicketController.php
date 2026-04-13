<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
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

        $query = Ticket::with('user', 'assignee')
            ->latest()
            ->paginate(15);

        // Handle filters
        if ($request->filled('status')) {
            $query = Ticket::with('user', 'assignee')
                ->where('status', $request->status)
                ->latest()
                ->paginate(15);
        }

        if ($request->filled('priority')) {
            $query = Ticket::with('user', 'assignee')
                ->where('priority', $request->priority)
                ->latest()
                ->paginate(15);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query = Ticket::with('user', 'assignee')
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->latest()
                ->paginate(15);
        }

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

        $ticket->load('user', 'replies.user', 'assignee');
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

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $ticket = Ticket::create([
            'user_id' => $validated['user_id'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'status' => 'open',
        ]);

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

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'message' => $validated['message'],
            'is_staff_reply' => true,
        ]);

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
        // TODO: Implement notification using NotificationService
        // Check if notify_ticket setting is enabled
        // Send email to admin or relevant reseller
    }

    /**
     * Notify about ticket reply
     */
    private function notifyTicketReplied(Ticket $ticket): void
    {
        // TODO: Implement notification using NotificationService
        // Send email to ticket owner
    }
}
