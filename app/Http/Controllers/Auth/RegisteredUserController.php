<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\RegistrationGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $validated = $request->validated();
        $resellerId = session('registration_reseller_id');

        $user = User::create([
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => null,
            'status' => 'inactive',
            'reseller_id' => $resellerId ?: null,
        ]);

        session()->forget('registration_reseller_id');

        try {
            $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('verification.code.show')
            ->with('email', $user->email)
            ->with('message', 'We sent a verification code to '.EmailVerificationService::deliverySummary($delivery).'. Please enter it below.');
    }
}
