<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Services\ResellerBoundaryService;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(private ResellerBoundaryService $boundary)
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

        // Platform customers only. A reseller's customers are messaged by the
        // reseller, from the reseller's SMS configuration, never from here.
        $customers = $this->boundary->platformCustomers()
            ->whereNotNull('phone')
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
            'recipients.*' => 'integer',
        ]);

        $smsService = app(SmsService::class);

        if (! $smsService->isConfigured()) {
            return back()->with('error', 'SMS service is not configured. Please configure SMS settings first.');
        }

        $message = $request->input('message');
        $refused = 0;

        if ($request->input('recipient_type') === 'all') {
            $recipients = $this->boundary->platformCustomers()
                ->whereNotNull('phone')
                ->pluck('phone')
                ->all();

            if (empty($recipients)) {
                return back()->with('error', 'No customers with phone numbers found.');
            }
        } else {
            // Ids come from the browser. The boundary is enforced here, not
            // in the list the page happened to render.
            $partition = $this->boundary->partitionPlatformRecipients((array) $request->input('recipients', []));
            $refused = $partition['refused'];
            $recipients = $partition['allowed']->whereNotNull('phone')->pluck('phone')->all();

            if (empty($recipients)) {
                return back()->with('error', $refused > 0
                    ? 'The selected customers belong to resellers and are messaged by their reseller, not from here.'
                    : 'Selected customers do not have phone numbers.');
            }
        }

        $result = $smsService->send($recipients, $message);

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        $response = back()->with('success', $result['message']);

        return $refused > 0
            ? $response->with('warning', "{$refused} selected customer(s) belong to resellers and were not messaged.")
            : $response;
    }
}
