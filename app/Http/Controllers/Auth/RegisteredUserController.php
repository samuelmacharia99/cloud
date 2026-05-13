<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EmailVerificationCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register-premium');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'agree' => ['required', 'accepted'],
        ]);

        // Create user with unverified email
        $user = User::create([
            'name' => $validated['name'],
            'company' => $validated['company'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => null,
        ]);

        // Generate verification code (6 digits)
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store verification code
        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Send verification email with code
        Mail::send('emails.verification-code', [
            'name' => $user->name,
            'code' => $code,
        ], function ($message) use ($user) {
            $message->to($user->email)
                    ->subject('Verify Your Email Address');
        });

        // Redirect to verification page
        return redirect()->route('verification.code.show')
                        ->with('email', $user->email)
                        ->with('message', 'We sent a verification code to your email. Please enter it below.');
    }
}
