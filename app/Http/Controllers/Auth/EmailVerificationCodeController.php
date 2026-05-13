<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EmailVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EmailVerificationCodeController extends Controller
{
    /**
     * Show the email verification code form.
     */
    public function show(Request $request): View
    {
        $email = $request->query('email') ?? session('email');
        return view('auth.verify-code', ['email' => $email]);
    }

    /**
     * Verify the email code and activate the account.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        // Find user by email
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return back()->withErrors(['email' => 'User not found.']);
        }

        // Find valid verification code
        $verificationCode = EmailVerificationCode::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->first();

        if (!$verificationCode) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        if ($verificationCode->isExpired()) {
            $verificationCode->delete();
            return back()->withErrors(['code' => 'Verification code has expired. Please request a new one.']);
        }

        // Mark email as verified and activate account
        $user->update(['email_verified_at' => now()]);
        $verificationCode->delete();

        // Log the user in
        Auth::login($user);

        return redirect()->route('dashboard')->with('success', 'Email verified! Welcome to ' . config('app.name'));
    }

    /**
     * Resend verification code.
     */
    public function resend(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return back()->withErrors(['email' => 'User not found.']);
        }

        if ($user->email_verified_at) {
            return back()->withErrors(['email' => 'Email is already verified.']);
        }

        // Delete old codes and create new one
        EmailVerificationCode::where('user_id', $user->id)->delete();

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Send verification email
        \Illuminate\Support\Facades\Mail::send('emails.verification-code', [
            'name' => $user->name,
            'code' => $code,
        ], function ($message) use ($user) {
            $message->to($user->email)
                    ->subject('Verify Your Email Address - New Code');
        });

        return back()->with('success', 'New verification code sent to your email.');
    }
}
