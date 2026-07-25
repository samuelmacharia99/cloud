<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Services\InAppNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerNotificationController extends Controller
{
    public function index(Request $request, InAppNotificationService $notifications): View
    {
        $user = $request->user();

        $items = CustomerNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(30);

        return view('customer.notifications.index', [
            'notifications' => $items,
            'unreadCount' => $notifications->unreadCount($user),
        ]);
    }

    public function markRead(Request $request, CustomerNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->markRead();

        if ($notification->action_url) {
            return redirect()->to($notification->action_url);
        }

        return back()->with('success', 'Marked as read.');
    }

    public function markAllRead(Request $request, InAppNotificationService $notifications): RedirectResponse
    {
        $notifications->markAllRead($request->user());

        return back()->with('success', 'All notifications marked as read.');
    }
}
