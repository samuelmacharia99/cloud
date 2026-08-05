<?php

namespace App\Http\Controllers;

use App\Helpers\PhoneHelper;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        // Admins should use the dedicated admin profile page
        if ($request->user()->is_admin) {
            return Redirect::route('admin.profile.edit');
        }

        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Display the user's security settings.
     */
    public function security(Request $request, TwoFactorService $twoFactorService): View
    {
        $user = $request->user();

        return view('profile.security', [
            'user' => $user,
            'canDeliverTwoFactor' => $twoFactorService->canDeliverCode($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Normalize phone number if provided
        if (! empty($validated['phone'])) {
            $validated['phone'] = PhoneHelper::normalize($validated['phone']);
        }

        $request->user()->fill($validated);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Log out user from all other sessions.
     */
    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::route('profile.security')->with('status', 'sessions-cleared');
    }

    public function enableTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $user = $request->user();

        if (! $twoFactorService->canDeliverCode($user)) {
            return Redirect::route('profile.security')
                ->with('error', 'Add a phone number or configure email delivery before enabling 2FA.');
        }

        try {
            $recoveryCodes = $twoFactorService->enable($user);

            return Redirect::route('profile.security')
                ->with('success', 'Two-factor authentication enabled successfully. Login codes will be sent by email and/or SMS.')
                ->with('recovery_codes', $recoveryCodes);
        } catch (\Exception $e) {
            return Redirect::route('profile.security')
                ->with('error', 'Failed to enable 2FA. '.$e->getMessage());
        }
    }

    public function disableTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        try {
            $twoFactorService->disable($request->user());

            return Redirect::route('profile.security')
                ->with('success', 'Two-factor authentication has been disabled.');
        } catch (\Exception $e) {
            return Redirect::route('profile.security')
                ->with('error', 'Failed to disable 2FA. '.$e->getMessage());
        }
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_enabled) {
            return Redirect::route('profile.security')
                ->with('error', '2FA is not enabled.');
        }

        try {
            $recoveryCodes = $twoFactorService->enable($user);

            return Redirect::route('profile.security')
                ->with('success', 'Recovery codes regenerated successfully.')
                ->with('recovery_codes', $recoveryCodes);
        } catch (\Exception $e) {
            return Redirect::route('profile.security')
                ->with('error', 'Failed to regenerate recovery codes. '.$e->getMessage());
        }
    }
}
