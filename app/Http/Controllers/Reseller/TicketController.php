<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ResellerScopeService;
use App\Services\TicketAttachmentService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ticket::class);

        $reseller = auth()->user();
        $customerIds = $this->scope->managedCustomerIds($reseller);

        $query = Ticket::with('user', 'assignee')
            ->where(function ($builder) use ($reseller, $customerIds) {
                $builder->where('user_id', $reseller->id)
                    ->orWhereIn('user_id', $customerIds);
            })
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $tickets = $query->paginate(15)->withQueryString();

        return view('reseller.tickets.index', compact('tickets'));
    }

    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);
        $ticket->load('user', 'replies.user', 'replies.attachments', 'assignee', 'attachments');

        return view('reseller.tickets.show', compact('ticket'));
    }

    public function create()
    {
        $this->authorize('create', Ticket::class);

        return view('reseller.tickets.create', [
            'priorityOptions' => ['low', 'medium', 'high', 'urgent'],
        ]);
    }

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

        return redirect()->route('reseller.tickets.show', $ticket)
            ->with('success', 'Ticket created successfully.');
    }

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

        if ($ticket->isClosed()) {
            $ticket->update(['status' => 'open', 'resolved_at' => null]);
        }

        return back()->with('success', 'Reply added successfully.');
    }

    public function close(Ticket $ticket)
    {
        $this->authorize('close', $ticket);

        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Ticket closed successfully.');
    }
}
