<?php

namespace App\Http\Controllers;

use App\Services\NotificationPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        private NotificationPreferenceService $preferences,
    ) {}

    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_admin) {
            return redirect()->route('admin.profile.edit');
        }

        $events = $this->preferences->configurableEventsForUser($user);
        $saved = $user->notificationPreferences()->get()->keyBy('event_key');

        return view('profile.notifications', [
            'user' => $user,
            'events' => $events,
            'saved' => $saved,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $events = array_keys($this->preferences->configurableEventsForUser($user));

        $validated = $request->validate([
            'preferences' => 'array',
            'preferences.*.email' => 'nullable|boolean',
            'preferences.*.sms' => 'nullable|boolean',
        ]);

        foreach ($events as $eventKey) {
            $pref = $validated['preferences'][$eventKey] ?? [];
            $this->preferences->updatePreference(
                $user,
                $eventKey,
                (bool) ($pref['email'] ?? true),
                (bool) ($pref['sms'] ?? true),
            );
        }

        return redirect()->route('profile.notifications')
            ->with('status', 'Notification preferences updated.');
    }
}
