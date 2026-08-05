<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAdminBroadcastEmailJob;
use App\Models\Email;
use App\Models\User;
use App\Services\EmailDeliveryService;
use App\Services\EmailPreviewService;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', Email::class);

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $today = now()->startOfDay();

        // Stats
        $totalSentToday = Email::sent()->where('created_at', '>=', $today)->count();
        $totalFailedToday = Email::failed()->where('created_at', '>=', $today)->count();
        $totalAllTime = Email::count();

        // Filter by status
        $status = $request->get('status', 'all');
        $query = Email::with('sentBy')->latest('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $emails = $query->paginate(20);

        $customers = $this->platformCustomersQuery()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.emails.index', compact(
            'totalSentToday',
            'totalFailedToday',
            'totalAllTime',
            'emails',
            'status',
            'customers',
        ));
    }

    public function send(Request $request, EmailDeliveryService $emailDelivery)
    {
        $this->authorize('create', Email::class);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'recipient_type' => ['required', 'in:all,custom'],
            'recipients' => ['required_if:recipient_type,custom', 'array'],
            'recipients.*' => ['integer', 'exists:users,id'],
        ]);

        if (! $emailDelivery->mailConfiguredFor(null)) {
            return back()
                ->withInput()
                ->with('error', 'Platform email (SMTP) is not configured. Configure it in Settings first.');
        }

        $recipients = $validated['recipient_type'] === 'all'
            ? $this->platformCustomersQuery()->get()
            : $this->platformCustomersQuery()
                ->whereIn('id', $validated['recipients'] ?? [])
                ->get();

        if ($recipients->isEmpty()) {
            return back()
                ->withInput()
                ->with('error', 'No platform customers matched the selected audience.');
        }

        $userIds = $recipients->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $count = count($userIds);
        $delaySeconds = SendAdminBroadcastEmailJob::DELAY_SECONDS;
        $estimatedMinutes = max(1, (int) ceil((($count - 1) * $delaySeconds) / 60));

        SendAdminBroadcastEmailJob::dispatch(
            $userIds,
            $validated['subject'],
            $validated['body'],
            0,
            auth()->id(),
        );

        $message = $count === 1
            ? 'Broadcast queued — 1 email will be sent shortly.'
            : "Broadcast queued for {$count} platform customers (1 email every {$delaySeconds}s, ~{$estimatedMinutes} min).";

        return redirect()
            ->route('admin.emails.index')
            ->with('success', $message);
    }

    /**
     * Platform (admin-owned) customers only — excludes resellers and reseller-managed customers.
     */
    private function platformCustomersQuery()
    {
        return User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('reseller_id')
            ->whereNotNull('email')
            ->where('email', '!=', '');
    }

    public function show(Email $email, EmailPreviewService $preview)
    {
        $this->authorize('view', $email);

        $email->load('user', 'sentBy');

        return view('admin.emails.show', [
            'email' => $email,
            'preview' => $preview,
            'customerHtml' => $preview->customerHtml($email),
            'plainTextContent' => $preview->plainTextContent($email),
            'recipientUser' => $preview->resolveRecipient($email),
            'fromName' => $preview->fromName($email),
            'fromAddress' => $preview->fromAddress($email),
            'eventLabel' => $preview->eventLabel($email),
            'branding' => $preview->branding($email),
        ]);
    }

    public function resend(Email $email, EmailDeliveryService $emailDelivery)
    {
        $this->authorize('resend', $email);

        try {
            $emailDelivery->resendLoggedEmail($email);

            return redirect()
                ->route('admin.emails.show', $email)
                ->with('success', 'Email resent successfully to '.$email->recipient.'.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.emails.show', $email)
                ->with('error', 'Failed to resend email: '.$e->getMessage());
        }
    }
}
