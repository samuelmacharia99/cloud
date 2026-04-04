<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', SmsLog::class);
            return $next($request);
        });
    }

    public function index()
    {
        $today = now()->startOfDay();

        // Stats
        $totalSentToday = SmsLog::sent()->where('created_at', '>=', $today)->count();
        $totalFailedToday = SmsLog::failed()->where('created_at', '>=', $today)->count();
        $totalAllTime = SmsLog::count();

        // Recent logs
        $logs = SmsLog::with('sentBy')
            ->latest('created_at')
            ->paginate(20);

        // Get active customers for recipient select
        $customers = User::where('is_admin', false)
            ->where('phone', '!=', null)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        return view('admin.sms.index', compact('totalSentToday', 'totalFailedToday', 'totalAllTime', 'logs', 'customers'));
    }

    public function send(Request $request)
    {
        $this->authorize('create', SmsLog::class);

        $request->validate([
            'message' => 'required|string|max:160',
            'recipient_type' => 'required|in:all,custom',
            'recipients' => 'required_if:recipient_type,custom|array',
        ]);

        $smsService = new SmsService();

        if (!$smsService->isConfigured()) {
            return back()->with('error', 'SMS service is not configured. Please configure SMS settings first.');
        }

        $message = $request->input('message');
        $recipientType = $request->input('recipient_type');

        if ($recipientType === 'all') {
            // Get all active customer phone numbers
            $recipients = User::where('is_admin', false)
                ->whereNotNull('phone')
                ->pluck('phone')
                ->toArray();

            if (empty($recipients)) {
                return back()->with('error', 'No customers with phone numbers found.');
            }
        } else {
            // Get selected customer phone numbers
            $recipients = User::whereIn('id', $request->input('recipients', []))
                ->whereNotNull('phone')
                ->pluck('phone')
                ->toArray();

            if (empty($recipients)) {
                return back()->with('error', 'Selected customers do not have phone numbers.');
            }
        }

        $result = $smsService->send($recipients, $message);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        } else {
            return back()->with('error', $result['message']);
        }
    }
}
