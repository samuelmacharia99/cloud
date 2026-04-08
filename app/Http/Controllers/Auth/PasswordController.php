<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use App\Services\SecurityService;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', SecurityService::getPasswordRule(), 'confirmed'],
        ]);

        $user = $request->user();

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Send email notification
        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user));
        } catch (\Exception $e) {
            \Log::warning('Failed to send password change email', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        // Send SMS notification
        if ($user->phone) {
            $smsService = new SmsService();
            $message = "Your Talksasa Cloud password has been changed successfully. If this wasn't you, please contact support immediately.";
            $smsService->send($user->phone, $message);
        }

        return back()->with('status', 'password-updated');
    }
}
