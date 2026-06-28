<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string|size:6']);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $record = EmailVerificationCode::where('user_id', $user->id)
            ->valid()
            ->where('code', $request->input('code'))
            ->first();

        if (!$record) {
            return back()->withErrors(['code' => 'Invalid or expired verification code.']);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($user->status !== 'active') {
            $user->update(['status' => 'active']);
        }

        $record->delete();

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
