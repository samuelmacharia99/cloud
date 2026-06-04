<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMpesaUrlsRequest;
use App\Http\Requests\TestSmsRequest;
use App\Http\Requests\TestSmtpRequest;
use App\Http\Requests\UpdateBrandingSettingsRequest;
use App\Http\Requests\UpdateMpesaSettingsRequest;
use App\Http\Requests\UpdateSmsSettingsRequest;
use App\Http\Requests\UpdateSmtpSettingsRequest;
use App\Http\Requests\UploadBrandingFileRequest;
use App\Jobs\ProvisionResellerSslJob;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerBrandingService;
use App\Services\ResellerMailService;
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
    private const SETTINGS_TABS = ['payment', 'sms', 'email', 'branding'];

    public function __construct(
        private ResellerSettingsService $settingsService,
        private TalksasaSmsService $smsService,
        private ResellerBrandingService $brandingService,
        private ResellerSslService $sslService,
        private ResellerBrandingResolver $brandingResolver,
        private ResellerMailService $resellerMail,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();

        return view('reseller.settings.index', [
            'user' => $user,
            'mpesaSettings' => $this->settingsService->getMpesaSettings($user),
            'smsSettings' => $this->settingsService->getSmsSettings($user),
            'smtpSettings' => $this->settingsService->getSmtpSettings($user),
            'brandingSettings' => $this->settingsService->getBrandingSettings($user),
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

            $this->resellerMail->sendTest($user, $request->input('test_email'));

            return back()->with('success', 'Test email sent to '.$request->input('test_email'));
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
        try {
            $user = auth()->user();
            $domain = $user->settings['branding']['custom_domain'] ?? null;

            if (empty($domain)) {
                return $this->redirectToSettingsTab('branding', [
                    'error' => 'Save a custom domain in branding settings before provisioning SSL.',
                ]);
            }

            if (! $this->sslService->isCertbotAvailable()) {
                return $this->redirectToSettingsTab('branding', [
                    'error' => 'SSL provisioning is not available on this server (certbot is not installed). Contact your platform administrator.',
                ]);
            }

            $dnsCheck = $this->sslService->checkDns($domain);
            if (! $dnsCheck['match']) {
                return $this->redirectToSettingsTab('branding', [
                    'error' => 'DNS is not pointing to this server yet. '.$dnsCheck['message'].' Expected server IP: '.$dnsCheck['server_ip'],
                ]);
            }

            @set_time_limit(300);

            $this->sslService->prepareManualProvision($user, 'manual');
            $result = $this->sslService->issueCertificate($user->fresh());

            $user->refresh();
            $ssl = $this->sslService->getSslStatus($user);

            if ($result['success'] ?? false) {
                $expires = ! empty($ssl['expires_at'])
                    ? ' Valid until '.\Carbon\Carbon::parse($ssl['expires_at'])->format('M d, Y').'.'
                    : '';

                return $this->redirectToSettingsTab('branding', [
                    'success' => 'SSL certificate is active for '.$domain.'.'.$expires,
                ]);
            }

            $failure = $this->sslService->resolveSslFailureDisplay($ssl);
            $errorMessage = $failure['error'] !== ''
                ? $failure['error']
                : 'SSL provisioning failed. Check server logs or try again in a few minutes.';

            if ($failure['show_output']) {
                $errorMessage .= "\n\n".$failure['output'];
            }

            return $this->redirectToSettingsTab('branding', [
                'error' => $errorMessage,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectToSettingsTab('branding', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception during SSL provisioning', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('branding', [
                'error' => 'Failed to provision SSL. Please try again.',
            ]);
        }
    }

    public function renewSsl(Request $request): RedirectResponse
    {
        try {
            $user = auth()->user();

            ProvisionResellerSslJob::dispatch($user->id, 'renew');

            return $this->redirectToSettingsTab('branding', [
                'success' => 'SSL renewal has been queued.',
            ]);
        } catch (\Exception $e) {
            Log::error('Exception during SSL renewal queue', [
                'error' => $e->getMessage(),
                'reseller_id' => auth()->id(),
                'exception' => $e,
            ]);

            return $this->redirectToSettingsTab('branding', [
                'error' => 'Failed to queue SSL renewal. Please try again.',
            ]);
        }
    }
}
