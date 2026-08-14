<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class EmailVerificationCodeController extends Controller
{
    /**
     * Show the email verification code form.
     */
    public function show(Request $request): View
    {
        $email = $request->query('email') ?? session('email');
        $phoneHint = null;
        $sessionEmail = session('email');
        $user = is_string($email) && is_string($sessionEmail)
            && hash_equals(strtolower($sessionEmail), strtolower($email))
            ? User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first()
            : null;

        if ($user?->phone) {
            $normalized = PhoneHelper::normalize($user->phone);
            $phoneHint = strlen($normalized) >= 4
                ? '***'.substr($normalized, -4)
                : null;
        }

        return view('auth.verify-code', [
            'email' => $email,
            'phoneHint' => $phoneHint,
        ]);
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

        $email = strtolower(trim($validated['email']));
        if ($this->tooManyAttempts('verify', $email, $request->ip())) {
            return back()->withErrors(['code' => 'The verification details are invalid or expired.']);
        }

        $this->recordAttempt('verify', $email, $request->ip());
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $verificationCode = $user
            ? EmailVerificationCode::where('user_id', $user->id)->latest('id')->first()
            : null;

        if (! $verificationCode || $verificationCode->isExpired() || ! $verificationCode->matches($validated['code'])) {
            if ($verificationCode?->isExpired()) {
                $verificationCode->delete();
            }

            return back()->withErrors(['code' => 'The verification details are invalid or expired.']);
        }

        if ($user->hasVerifiedEmail()) {
            $verificationCode->delete();

            return back()->withErrors(['code' => 'The verification details are invalid or expired.']);
        }

        $user->update([
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $verificationCode->delete();
        $this->clearAttempts('verify', $email, $request->ip());

        // Log the user in
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Email verified! Welcome to '.config('app.name'));
    }

    /**
     * Resend verification code.
     */
    public function resend(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        if (! $this->tooManyAttempts('resend', $email, $request->ip())) {
            $this->recordAttempt('resend', $email, $request->ip());
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user && ! $user->hasVerifiedEmail()) {
                try {
                    app(EmailVerificationService::class)->sendVerificationCode($user);
                } catch (\Throwable) {
                    // Keep the public response uniform to prevent account enumeration.
                }
            }
        }

        return back()->with('success', 'If an unverified account matches that email, a verification code will be sent.');
    }

    private function tooManyAttempts(string $action, string $email, string $ip): bool
    {
        $limit = (int) config('security.rate_limit.email_verification', 5);

        return RateLimiter::tooManyAttempts($this->rateKey($action, 'email', $email), $limit)
            || RateLimiter::tooManyAttempts($this->rateKey($action, 'ip', $ip), $limit);
    }

    private function recordAttempt(string $action, string $email, string $ip): void
    {
        RateLimiter::hit($this->rateKey($action, 'email', $email), 3600);
        RateLimiter::hit($this->rateKey($action, 'ip', $ip), 3600);
    }

    private function clearAttempts(string $action, string $email, string $ip): void
    {
        RateLimiter::clear($this->rateKey($action, 'email', $email));
        RateLimiter::clear($this->rateKey($action, 'ip', $ip));
    }

    private function rateKey(string $action, string $dimension, string $value): string
    {
        return 'email-verification:'.$action.':'.$dimension.':'.hash('sha256', strtolower($value));
    }
}
