<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMpesaUrlsRequest;
use App\Http\Requests\TestSmsRequest;
use App\Http\Requests\TestSmtpRequest;
use App\Http\Requests\UpdateBrandingSettingsRequest;
use App\Http\Requests\UpdateMpesaSettingsRequest;
use App\Http\Requests\UpdateResellerNameserverSettingsRequest;
use App\Http\Requests\UpdateSmsSettingsRequest;
use App\Http\Requests\UpdateSmtpSettingsRequest;
use App\Http\Requests\UploadBrandingFileRequest;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerBrandingService;
use App\Services\ResellerMailService;
use App\Services\ResellerNameserverService;
use App\Services\ResellerSettingsService;
use App\Services\ResellerSslService;
use App\Services\TalksasaSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SettingController extends Controller
{
    private const SETTINGS_TABS = ['payment', 'sms', 'email', 'branding', 'nameservers'];

    public function __construct(
        private ResellerSettingsService $settingsService,
        private TalksasaSmsService $smsService,
        private ResellerBrandingService $brandingService,
        private ResellerSslService $sslService,
        private ResellerBrandingResolver $brandingResolver,
        private ResellerMailService $resellerMail,
        private ResellerNameserverService $nameserverService,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();

        return view('reseller.settings.index', [
            'user' => $user,
            'mpesaSettings' => $this->settingsService->getMpesaSettings($user),
            'smsSettings' => $this->settingsService->getSmsSettings($user),
            'smtpSettings' => $this->settingsService->getSmtpSettingsForDisplay($user),
            'brandingSettings' => $this->settingsService->getBrandingSettings($user),
            'nameserverSettings' => $this->nameserverService->getSettings($user),
            'platformNameservers' => $this->nameserverService->platformDefaults(),
            'brandingStatus' => $this->brandingResolver->status($user),
            'registrationInviteUrl' => $this->brandingResolver->signedRegistrationUrl($user),
            'activeSettingsTab' => $this->resolveSettingsTab($request),
        ]);
    }

    private function resolveSettingsTab(Request $request): string
    {
        $tab = (string) $request->query('tab', session('settings_tab', 'payment'));

        return in_array($tab, self::SETTINGS_TABS, true) ? $tab : 'payment';
    }

    /**
     * @param  array<string, mixed>  $with
     */
    private function redirectToSettingsTab(string $tab, array $with = []): RedirectResponse
    {
        if (! in_array($tab, self::SETTINGS_TABS, true)) {
            $tab = 'payment';
        }

        return redirect()
            ->route('reseller.settings.index', ['tab' => $tab])
            ->with(array_merge(['settings_tab' => $tab], $with));
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
            $message = 'This is a test SMS from '.($this->brandingResolver->forReseller($user)['company_name'] ?? config('app.name')).'. Your SMS configuration is working correctly!';

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

                return back()->with('success', 'Test SMS sent successfully to '.$phone.'. Check your phone for the message.');
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

            return $this->redirectToSettingsTab('email', [
                'success' => 'SMTP settings updated successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update SMTP settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('email', [
                'error' => 'Failed to update SMTP settings. Please try again.',
            ]);
        }
    }

    public function testSmtp(TestSmtpRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $smtpSettings = $this->settingsService->getSmtpSettings($user);

            if (empty($smtpSettings['host']) || empty($smtpSettings['from_address'])) {
                return $this->redirectToSettingsTab('email', [
                    'error' => 'Save your SMTP host and from address before sending a test email.',
                ]);
            }

            if (empty($smtpSettings['password'])) {
                return $this->redirectToSettingsTab('email', [
                    'error' => 'Save your SMTP password before sending a test email.',
                ]);
            }

            if (! $this->resellerMail->resellerSmtpEnabled($user)) {
                return $this->redirectToSettingsTab('email', [
                    'error' => 'Enable SMTP and save your settings before sending a test email.',
                ]);
            }

            Log::info('SMTP test connection initiated', [
                'reseller_id' => auth()->id(),
                'test_email' => $request->input('test_email'),
            ]);

            $this->resellerMail->sendTest($user, $request->input('test_email'));

            return $this->redirectToSettingsTab('email', [
                'success' => 'Test email sent to '.$request->input('test_email'),
            ]);
        } catch (\RuntimeException $e) {
            return $this->redirectToSettingsTab('email', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to test SMTP', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('email', [
                'error' => 'Failed to send test email. Check your SMTP credentials and try again.',
            ]);
        }
    }

    public function updateBranding(UpdateBrandingSettingsRequest $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $previousDomain = $user->settings['branding']['custom_domain'] ?? null;
            $this->settingsService->updateBrandingSettings($user, $request->validated());
            $this->brandingResolver->forgetDomainCache($previousDomain);
            $this->brandingResolver->forgetDomainCache($request->input('custom_domain'));

            return $this->redirectToSettingsTab('branding', [
                'success' => 'Branding settings updated successfully. SSL will be provisioned automatically once DNS is configured.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update branding settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('branding', [
                'error' => 'Failed to update branding settings. Please try again.',
            ]);
        }
    }

    public function updateNameservers(UpdateResellerNameserverSettingsRequest $request): RedirectResponse
    {
        try {
            $this->nameserverService->updateSettings(auth()->user(), $request->validated());

            return $this->redirectToSettingsTab('nameservers', [
                'success' => 'Nameserver settings saved successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update reseller nameserver settings', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('nameservers', [
                'error' => 'Failed to save nameserver settings. Please try again.',
            ]);
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

            return $this->redirectToSettingsTab('branding', [
                'success' => "{$typeLabel} uploaded successfully.",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to upload branding file', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'type' => $request->input('type'),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('branding', [
                'error' => 'Failed to upload file. Please try again.',
            ]);
        }
    }

    public function deleteBrandingFile(Request $request): RedirectResponse
    {
        try {
            $user = auth()->user();
            $type = $request->input('type');

            if (! in_array($type, ['logo', 'favicon'])) {
                return $this->redirectToSettingsTab('branding', [
                    'error' => 'Invalid file type.',
                ]);
            }

            $this->brandingService->deleteFile($user, $type);

            $typeLabel = ucfirst($type);

            return $this->redirectToSettingsTab('branding', [
                'success' => "{$typeLabel} deleted successfully.",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete branding file', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'type' => $request->input('type'),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('branding', [
                'error' => 'Failed to delete file. Please try again.',
            ]);
        }
    }

    public function checkSslDns(Request $request): JsonResponse
    {
        try {
            $domain = $request->query('domain');

            if (empty($domain)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domain is required.',
                ], 400);
            }

            $dnsCheck = $this->sslService->checkDns($domain);
            $certbotAvailable = $this->sslService->isCertbotAvailable();

            Log::info('DNS check performed for custom domain', [
                'reseller_id' => auth()->id(),
                'domain' => $domain,
                'resolves' => $dnsCheck['resolves'],
                'match' => $dnsCheck['match'],
                'certbot_available' => $certbotAvailable,
            ]);

            return response()->json([
                'success' => true,
                'resolves' => $dnsCheck['resolves'],
                'match' => $dnsCheck['match'],
                'domain_ip' => $dnsCheck['domain_ip'],
                'server_ip' => $dnsCheck['server_ip'],
                'message' => $dnsCheck['message'],
                'certbot_available' => $certbotAvailable,
            ]);
        } catch (\Exception $e) {
            Log::error('DNS check failed', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check DNS: '.$e->getMessage(),
            ], 500);
        }
    }

    public function issueSsl(Request $request): RedirectResponse
    {
        return $this->provisionSsl($request);
    }

    public function provisionSsl(Request $request): RedirectResponse
    {
        return $this->redirectToSettingsTab('branding', [
            'success' => 'SSL is installed on the server via command line. Use Check DNS above to verify your domain, and run scripts/reseller-ssl/provision.sh on the host.',
        ]);
    }

    public function renewSsl(Request $request): RedirectResponse
    {
        return $this->redirectToSettingsTab('branding', [
            'success' => 'Renew SSL on the server with: sudo scripts/reseller-ssl/provision.sh --renew (see docs/RESELLER_SSL.md).',
        ]);
    }
}
