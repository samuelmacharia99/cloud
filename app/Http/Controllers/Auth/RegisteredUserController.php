<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Mail\VerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(RegistrationGuardService $guard): View
    {
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

        $user = User::create([
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => null,
            'status' => 'inactive',
        ]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(30),
        ]);

        Mail::to($user->email)->send(new VerificationCodeMail($user->name, $code));

        return redirect()->route('verification.code.show')
            ->with('email', $user->email)
            ->with('message', 'We sent a verification code to your email. Please enter it below.');
    }
}
