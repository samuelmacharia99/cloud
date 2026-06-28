<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password-premium');
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if (! $user) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        $token = Password::broker()->createToken($user);
        $sent = app(AuthEmailService::class)->sendPasswordReset($user, $token);

        if (! $sent) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'We could not send a password reset email. Please try again later or contact support.',
                ]);
        }

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
