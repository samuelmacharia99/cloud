<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    /**
     * Show 2FA verification page
     */
    public function verify(Request $request): View|RedirectResponse
    {
        $userId = $request->session()->get('two_factor_user_id');

        if (!$userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (!$user || !$user->two_factor_enabled) {
            $request->session()->forget('two_factor_user_id');
            return redirect()->route('login');
        }

        return view('auth.two-factor-verify', [
            'user' => $user,
        ]);
    }

    /**
     * Verify the 2FA code
     */
    public function verifyCode(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $userId = $request->session()->get('two_factor_user_id');

        if (!$userId) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = User::find($userId);

        if (!$user || !$user->two_factor_enabled) {
            $request->session()->forget('two_factor_user_id');
            return redirect()->route('login')->with('error', 'Invalid 2FA session');
        }

        // Verify the code
        if (!$twoFactorService->verifyCode($user, $request->code)) {
            return back()->withErrors([
                'code' => 'Invalid or expired verification code.',
            ]);
        }

        // Code verified, log in the user
        Auth::login($user);
        $request->session()->forget('two_factor_user_id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('success', '2FA verification successful. Welcome back!');
    }

    /**
     * Use a recovery code instead of the 2FA code
     */
    public function useRecoveryCode(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $userId = $request->session()->get('two_factor_user_id');

        if (!$userId) {
            return redirect()->route('login');
        }

        $request->validate([
            'recovery_code' => 'required|string',
        ]);

        $user = User::find($userId);

        if (!$user || !$user->two_factor_enabled) {
            $request->session()->forget('two_factor_user_id');
            return redirect()->route('login')->with('error', 'Invalid 2FA session');
        }

        // Verify the recovery code
        if (!$twoFactorService->verifyRecoveryCode($user, strtoupper($request->recovery_code))) {
            return back()->withErrors([
                'recovery_code' => 'Invalid recovery code.',
            ]);
        }

        // Recovery code verified, log in the user
        Auth::login($user);
        $request->session()->forget('two_factor_user_id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('warning', 'Logged in using recovery code. Consider regenerating your 2FA codes.');
    }
}
