<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationCodeController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::get('register/generate-password', [RegisteredUserController::class, 'generatePassword'])
        ->name('register.generate-password');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware(['throttle:5,1', 'registration.throttle']);

    // Email verification code routes
    Route::get('verify-email-code', [EmailVerificationCodeController::class, 'show'])
        ->name('verification.code.show');

    Route::post('verify-email-code', [EmailVerificationCodeController::class, 'verify'])
        ->name('verification.code.verify');

    Route::post('resend-verification-code', [EmailVerificationCodeController::class, 'resend'])
        ->name('verification.code.resend');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.store');

    // Two-Factor Authentication
    Route::get('auth/two-factor-verify', [TwoFactorController::class, 'verify'])
        ->name('auth.two-factor.verify');

    Route::post('auth/two-factor-verify', [TwoFactorController::class, 'verifyCode'])
        ->middleware('throttle:5,1')
        ->name('auth.two-factor.verify-code');

    Route::post('auth/two-factor-recovery', [TwoFactorController::class, 'useRecoveryCode'])
        ->middleware('throttle:5,1')
        ->name('auth.two-factor.use-recovery-code');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('verify-email', VerifyEmailController::class)
        ->middleware('throttle:6,1')
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
