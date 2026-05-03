<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMpesaSettingsRequest;
use App\Http\Requests\UpdateSmsSettingsRequest;
use App\Http\Requests\UpdateSmtpSettingsRequest;
use App\Http\Requests\RegisterMpesaUrlsRequest;
use App\Http\Requests\TestSmsRequest;
use App\Http\Requests\TestSmtpRequest;
use App\Http\Requests\UpdateBrandingSettingsRequest;
use App\Http\Requests\UploadBrandingFileRequest;
use App\Services\ResellerSettingsService;
use App\Services\TalksasaSmsService;
use App\Services\ResellerBrandingService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(
        private ResellerSettingsService $settingsService,
        private TalksasaSmsService $smsService,
        private ResellerBrandingService $brandingService
    ) {}

    public function index(): View
    {
        $user = auth()->user();

        return view('reseller.settings.index', [
            'user' => $user,
            'mpesaSettings' => $this->settingsService->getMpesaSettings($user),
            'smsSettings' => $this->settingsService->getSmsSettings($user),
            'smtpSettings' => $this->settingsService->getSmtpSettings($user),
            'brandingSettings' => $this->settingsService->getBrandingSettings($user),
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
            $phone = $request->input('phone');
            $message = 'This is a test SMS from Talksasa Cloud. Your SMS configuration is working correctly!';

            Log::info('Test SMS: Initiating', [
                'reseller_id' => $user->id,
                'phone' => $phone,
            ]);

            // Send test SMS
            $result = $this->smsService->sendSms($user, $phone, $message);

            if ($result['success']) {
                Log::info('Test SMS: Sent successfully', [
                    'reseller_id' => $user->id,
                    'queue_uid' => $result['response']['data']['queue_uid'] ?? null,
                    'talksasa_status' => $result['talksasa_status'],
                ]);

                return back()->with('success', 'Test SMS sent successfully to ' . $phone . '. Check your phone for the message.');
            } else {
                Log::warning('Test SMS: API rejected request', [
                    'reseller_id' => $user->id,
                    'response' => $result['response'],
                ]);

                $errorMessage = $result['message'] ?? 'Failed to send test SMS';
                return back()->with('error', $errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('Test SMS: Exception occurred', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to send test SMS. Check logs for details.');
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

    public function updateBranding(UpdateBrandingSettingsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $this->settingsService->updateBrandingSettings($user, $request->validated());

            return back()->with('success', 'Branding settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update branding settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to update branding settings. Please try again.');
        }
    }

    public function uploadBrandingFile(UploadBrandingFileRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $type = $request->input('type');
            $file = $request->file('file');

            $this->brandingService->uploadFile($user, $file, $type);

            $typeLabel = ucfirst($type);
            return back()->with('success', "{$typeLabel} uploaded successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to upload branding file', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'type' => $request->input('type'),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to upload file. Please try again.');
        }
    }

    public function deleteBrandingFile(Request $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $type = $request->input('type');

            if (!in_array($type, ['logo', 'favicon'])) {
                return back()->with('error', 'Invalid file type.');
            }

            $this->brandingService->deleteFile($user, $type);

            $typeLabel = ucfirst($type);
            return back()->with('success', "{$typeLabel} deleted successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to delete branding file', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'type' => $request->input('type'),
                'exception' => $e,
            ]);

            return back()->with('error', 'Failed to delete file. Please try again.');
        }
    }
}
