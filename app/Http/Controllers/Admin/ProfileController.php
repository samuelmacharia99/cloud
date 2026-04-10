<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\PhoneHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->is_admin) {
                abort(403);
            }
            return $next($request);
        });
    }

    /**
     * Show the admin profile edit page
     */
    public function edit()
    {
        $admin = auth()->user();

        \Log::info('Admin profile viewed', [
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
        ]);

        return view('admin.profile.edit', compact('admin'));
    }

    /**
     * Update admin profile information
     */
    public function update(Request $request)
    {
        $admin = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($admin->id)],
            'phone' => 'nullable|string|max:20',
            'notification_phones' => 'nullable|array|max:10',
            'notification_phones.*' => 'nullable|string|max:20',
        ]);

        // Filter out empty notification phones
        $notificationPhones = array_filter($validated['notification_phones'] ?? []);

        // Normalize each notification phone
        $notificationPhones = array_map(
            fn($phone) => PhoneHelper::normalize($phone),
            $notificationPhones
        );

        // Remove duplicates
        $notificationPhones = array_unique($notificationPhones);
        $notificationPhones = array_values($notificationPhones); // Re-index array

        \Log::info('Admin profile update started', [
            'admin_id' => $admin->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'notification_phones_count' => count($notificationPhones),
        ]);

        // Update the admin user
        try {
            $admin->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'notification_phones' => count($notificationPhones) > 0 ? $notificationPhones : null,
            ]);

            \Log::info('Admin profile updated successfully', [
                'admin_id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'notification_phones' => json_encode($admin->notification_phones),
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('success', 'Profile updated successfully! ' . count($notificationPhones) . ' notification phone(s) saved.');
        } catch (\Exception $e) {
            \Log::error('Admin profile update failed', [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('error', 'Failed to update profile. ' . $e->getMessage());
        }
    }
}
