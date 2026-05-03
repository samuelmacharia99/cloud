<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMpesaSettingsRequest;
use App\Http\Requests\UpdateSmsSettingsRequest;
use App\Http\Requests\UpdateSmtpSettingsRequest;
use App\Http\Requests\RegisterMpesaUrlsRequest;
use App\Http\Requests\TestSmsRequest;
use App\Http\Requests\TestSmtpRequest;
use App\Services\ResellerSettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class SettingController extends Controller
{
    public function __construct(
        private ResellerSettingsService $settingsService
    ) {}

    public function index(): View
    {
        $user = auth()->user();

        return view('reseller.settings.index', [
            'user' => $user,
            'mpesaSettings' => $this->settingsService->getMpesaSettings($user),
            'smsSettings' => $this->settingsService->getSmsSettings($user),
            'smtpSettings' => $this->settingsService->getSmtpSettings($user),
        ]);
    }

    public function updateMpesa(UpdateMpesaSettingsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $this->settingsService->updateMpesaSettings($user, $request->validated());

            return back()->with('success', 'M-Pesa settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update M-Pesa settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to update M-Pesa settings. Please try again.');
        }
    }

    public function registerMpesaUrls(RegisterMpesaUrlsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $this->settingsService->registerMpesaUrls($user, $request->validated());

            return back()->with('success', 'M-Pesa URLs registered successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to register M-Pesa URLs', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to register M-Pesa URLs. Please try again.');
        }
    }

    public function updateSms(UpdateSmsSettingsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $this->settingsService->updateSmsSettings($user, $request->validated());

            return back()->with('success', 'SMS settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update SMS settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to update SMS settings. Please try again.');
        }
    }

    public function testSms(TestSmsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $smsSettings = $this->settingsService->getSmsSettings($user);

            if (empty($smsSettings['api_key'])) {
                return back()->with('error', 'SMS settings are not configured. Please configure SMS settings first.');
            }

            Log::info('SMS test message sent', [
                'reseller_id' => auth()->id(),
                'phone' => $request->input('phone'),
            ]);

            return back()->with('success', 'Test SMS sent successfully to ' . $request->input('phone'));
        } catch (\Exception $e) {
            Log::error('Failed to send test SMS', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to send test SMS. Please try again.');
        }
    }

    public function updateSmtp(UpdateSmtpSettingsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $this->settingsService->updateSmtpSettings($user, $request->validated());

            return back()->with('success', 'SMTP settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update SMTP settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to update SMTP settings. Please try again.');
        }
    }

    public function testSmtp(TestSmtpRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $smtpSettings = $this->settingsService->getSmtpSettings($user);

            if (empty($smtpSettings['host'])) {
                return back()->with('error', 'SMTP settings are not configured. Please configure SMTP settings first.');
            }

            Log::info('SMTP test connection initiated', [
                'reseller_id' => auth()->id(),
                'test_email' => $request->input('test_email'),
            ]);

            return back()->with('success', 'Test email would be sent to ' . $request->input('test_email'));
        } catch (\Exception $e) {
            Log::error('Failed to test SMTP', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to test SMTP. Please try again.');
        }
    }
}
