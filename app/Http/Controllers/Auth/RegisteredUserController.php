<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\RegistrationGuardService;
use App\Services\UserCurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request, RegistrationGuardService $guard): View
    {
        if ($request->hasValidSignature() && $request->query('reseller')) {
            session(['registration_reseller_id' => (int) $request->query('reseller')]);
        }

        return view('auth.register-premium', [
            'registrationToken' => $guard->makeFormToken(),
        ]);
    }

    public function store(RegisterUserRequest $request, RegistrationGuardService $guard): RedirectResponse
    {
        if ($guard->shouldRejectAsBot($request)) {
            return $guard->fakeSuccessRedirect($request);
        }

        if ($timingError = $guard->submissionTimingError($request)) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['registration_token' => $timingError]);
        }

        $validated = $request->validated();
        $resellerId = session('registration_reseller_id');

        try {
            [$user, $delivery] = DB::transaction(function () use ($validated, $resellerId) {
                $user = User::create([
                    'name' => $validated['name'],
                    'company' => $validated['company'] ?? null,
                    'country' => $validated['country'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'email_verified_at' => null,
                    'status' => 'inactive',
                    'reseller_id' => $resellerId ?: null,
                ]);

                app(UserCurrencyService::class)->syncFromCountry($user, true);
                $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);

                return [$user, $delivery];
            });
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => $e->getMessage()]);
        }

        session()->forget('registration_reseller_id');

        return redirect()->route('verification.code.show')
            ->with('email', $user->email)
            ->with('message', 'We sent a verification code to '.EmailVerificationService::deliverySummary($delivery).'. Please enter it below.');
    }
}
