<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\RegistrationContextService;
use App\Services\RegistrationGuardService;
use App\Services\SecurityService;
use App\Services\Telegram\TelegramMonitorBridge;
use App\Services\UserCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request, RegistrationGuardService $guard, RegistrationContextService $registrationContext): View
    {
        if ($request->hasValidSignature() && $request->query('reseller')) {
            session(['registration_reseller_id' => (int) $request->query('reseller')]);
        }

        return view('auth.register', [
            'registrationToken' => $guard->makeFormToken(),
            'requiresPhone' => $registrationContext->requiresPhoneCapture($request),
        ]);
    }

    public function generatePassword(Request $request): JsonResponse
    {
        $length = (int) $request->input('length', 16);

        return response()->json([
            'password' => SecurityService::generateSecurePassword($length),
        ]);
    }

    public function store(RegisterUserRequest $request, RegistrationGuardService $guard): RedirectResponse
    {
        if ($guard->shouldRejectAsBot($request)) {
            return $guard->rejectBotSubmission($request);
        }

        if ($timingError = $guard->submissionTimingError($request)) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('registrationToken', $guard->makeFormToken())
                ->withErrors(['registration_token' => $timingError]);
        }

        $validated = $request->validated();
        $resellerId = session('registration_reseller_id');
        $displayName = $guard->buildDisplayName(
            $validated['first_name'],
            $validated['last_name'] ?? null,
        );

        try {
            $user = DB::transaction(function () use ($validated, $resellerId, $displayName) {
                $user = User::create([
                    'name' => $displayName,
                    'company' => $validated['company'] ?? null,
                    'country' => $validated['country'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'email_verified_at' => null,
                    'status' => 'inactive',
                    'reseller_id' => $resellerId ?: null,
                ]);

                app(UserCurrencyService::class)->syncFromCountry($user, true);

                return $user;
            });
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('registrationToken', $guard->makeFormToken())
                ->withErrors(['email' => $e->getMessage()]);
        }

        session()->forget('registration_reseller_id');

        app(TelegramMonitorBridge::class)->userRegistered($user);

        try {
            $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);
        } catch (\Throwable $e) {
            return redirect()->route('verification.code.show')
                ->with('email', $user->email)
                ->withErrors(['email' => $e->getMessage()])
                ->with('message', 'Your account was created, but we could not send a verification code. Use Resend below or contact support.');
        }

        return redirect()->route('verification.code.show')
            ->with('email', $user->email)
            ->with('message', 'We sent a verification code to '.EmailVerificationService::deliverySummary($delivery).'. Please enter it below.');
    }
}
