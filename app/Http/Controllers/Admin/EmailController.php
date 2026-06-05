<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Services\EmailDeliveryService;
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

        return view('admin.emails.index', compact('totalSentToday', 'totalFailedToday', 'totalAllTime', 'emails', 'status'));
    }

    public function show(Email $email)
    {
        $this->authorize('view', $email);

        return view('admin.emails.show', compact('email'));
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
