<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\PhoneHelper;
use App\Services\TwoFactorService;
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

    /**
     * Enable 2FA for the admin user
     */
    public function enableTwoFactor(Request $request, TwoFactorService $twoFactorService)
    {
        $admin = auth()->user();

        // Check if admin has a phone number
        if (!$admin->phone) {
            return redirect()->route('admin.profile.edit')
                ->with('error', 'Please set your phone number first before enabling 2FA.');
        }

        try {
            $recoveryCodes = $twoFactorService->enable($admin);

            \Log::info('Admin 2FA enabled', [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('success', 'Two-factor authentication enabled successfully!')
                ->with('recovery_codes', $recoveryCodes);
        } catch (\Exception $e) {
            \Log::error('Failed to enable 2FA', [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('error', 'Failed to enable 2FA. ' . $e->getMessage());
        }
    }

    /**
     * Disable 2FA for the admin user
     */
    public function disableTwoFactor(Request $request, TwoFactorService $twoFactorService)
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $admin = auth()->user();

        try {
            $twoFactorService->disable($admin);

            \Log::info('Admin 2FA disabled', [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('success', 'Two-factor authentication has been disabled.');
        } catch (\Exception $e) {
            \Log::error('Failed to disable 2FA', [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('error', 'Failed to disable 2FA. ' . $e->getMessage());
        }
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateRecoveryCodes(Request $request, TwoFactorService $twoFactorService)
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $admin = auth()->user();

        if (!$admin->two_factor_enabled) {
            return redirect()->route('admin.profile.edit')
                ->with('error', '2FA is not enabled.');
        }

        try {
            $recoveryCodes = $twoFactorService->enable($admin); // Re-enable to regenerate codes

            \Log::info('Admin recovery codes regenerated', [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('success', 'Recovery codes regenerated successfully!')
                ->with('recovery_codes', $recoveryCodes);
        } catch (\Exception $e) {
            \Log::error('Failed to regenerate recovery codes', [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.profile.edit')
                ->with('error', 'Failed to regenerate recovery codes. ' . $e->getMessage());
        }
    }
}
