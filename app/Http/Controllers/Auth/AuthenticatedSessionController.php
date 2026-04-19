<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login-premium');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        // Check if user has 2FA enabled
        if ($user->two_factor_enabled && $user->phone) {
            // Send 2FA code
            $codeSent = $twoFactorService->sendCode($user);

            // If SMS failed, show error
            if (!$codeSent) {
                Auth::logout();
                return redirect()->route('login')
                    ->with('error', 'Failed to send 2FA code. SMS service may be unavailable. Please try again or contact support.');
            }

            // Store the user ID in session for later verification
            $request->session()->put('two_factor_user_id', $user->id);

            // Log out temporarily (don't create session yet)
            Auth::logout();

            return redirect()->route('auth.two-factor.verify');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
