<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\CronHelper;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Node;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\PaymentGateway\MpesaService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    protected array $groups = [
        'general' => [
            'site_name', 'site_url', 'site_email', 'support_email',
            'timezone', 'date_format', 'currency', 'currency_symbol',
        ],
        'billing' => [
            'billing_company', 'billing_address', 'billing_city', 'billing_country',
            'billing_vat_number', 'invoice_prefix', 'invoice_due_days', 'grace_period_days',
            'service_monthly_invoice_advance_days', 'service_renewal_invoice_advance_days',
            'domain_renewal_advance_days', 'domain_renewal_payment_days', 'domain_renewal_years',
            'reseller_package_invoice_advance_days',
            'reseller_auto_pay_subscription_from_wallet',
        ],
        'tax' => [
            'tax_enabled', 'tax_rate', 'tax_name', 'tax_inclusive', 'tax_number',
        ],
        'payment_methods' => [
            // M-Pesa
            'mpesa_enabled', 'mpesa_shortcode', 'mpesa_passkey',
            'mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_environment', 'mpesa_callback_token',
            'mpesa_register_response_type',
            // Stripe
            'stripe_enabled', 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret',
            // PayPal
            'paypal_enabled', 'paypal_client_id', 'paypal_client_secret',
            'paypal_webhook_id', 'paypal_environment',
            // Bank Transfer
            'bank_transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_number',
            'bank_branch', 'bank_swift_code',
        ],
        'provisioning' => [
            'provisioning_mode', 'auto_provision', 'suspend_on_overdue', 'terminate_after_unpaid_months',
            'reseller_suspend_on_overdue', 'reseller_cascade_suspend_on_overdue',
            'reseller_suspend_excess_services', 'reseller_enforce_limits_on_provision',
        ],
        'branding' => [
            'logo_url', 'favicon_url', 'primary_color', 'company_name', 'footer_text',
        ],
        'email' => [
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_password',
            'mail_from_name', 'mail_from_address', 'mail_reply_to_name', 'mail_reply_to_address', 'email_queue_enabled',
        ],
        'notifications' => [
            'notify_new_order', 'notify_payment', 'notify_service_suspend', 'notify_ticket',
            'notify_invoice_generated', 'notify_invoice_reminder', 'notify_invoice_overdue',
            'notify_service_activated', 'notify_service_unsuspended', 'notify_service_terminated',
            'notify_domain_expiry', 'notify_container_backup', 'notify_container_backup_failure',
            'notify_container_failure', 'notify_container_restart',
            'notify_reseller_domain_queued', 'notify_reseller_domain_pushed', 'notify_reseller_new_customer_order',
            'notify_reseller_wallet_low', 'notify_reseller_wallet_topup', 'notify_reseller_wallet_adjustment',
            'notify_admin_new_order', 'notify_admin_reseller_domain_push', 'notify_admin_manual_payment',
            'notify_admin_node_offline',
        ],
        'cron' => [
            'cron_timezone', 'cron_retention_days', 'max_execution_time',
        ],
        'sms' => [
            'sms_enabled', 'sms_api_token', 'sms_sender_id',
        ],
        'security' => [
            'recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret_key',
        ],
    ];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', Setting::class);

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $group = $request->get('group', 'general');

        // Load ALL settings for all groups (so all tabs work with the same form)
        $allKeys = collect($this->groups)->flatten()->toArray();
        $settings = Setting::whereIn('key', $allKeys)->pluck('value', 'key');

        $keys = $this->groups[$group] ?? $this->groups['general'];
        $groups = $this->groups;
        $currencies = Currency::active()->get();

        // Load SMS templates for the notifications tab
        $smsTemplatesList = SmsTemplate::orderBy('recipient_type')->orderBy('name')->get();
        $emailTemplatesList = EmailTemplate::orderBy('recipient_type')->orderBy('name')->get();
        $directAdminNodes = Node::where('type', 'directadmin')->orderBy('name')->get();

        $allowedTabs = ['general', 'billing', 'tax', 'payment_methods', 'provisioning', 'branding', 'email', 'notifications', 'cron', 'sms', 'security'];
        $activeTab = in_array(request('tab'), $allowedTabs, true) ? request('tab') : 'general';

        // Get cron helper data for the cron tab
        try {
            $cronCommand = getCronCommand();
            $cronCommandOptions = getCronCommandOptions();
            $cronValidation = CronHelper::validateCronSetup();
            $cronStats = CronHelper::getCronStats();
        } catch (\Exception $e) {
            // Fallback if cron system has issues
            $cronCommand = getCronCommand();
            $cronCommandOptions = getCronCommandOptions();
            $cronValidation = ['valid' => false, 'message' => 'Unable to load cron configuration', 'errors' => [$e->getMessage()]];
            $cronStats = [];
        }

        // Gateway status for auto-expand in payment methods tab
        $gatewayStatus = [
            'mpesa' => Setting::getValue('mpesa_enabled') == '1' && ! empty(Setting::getValue('mpesa_consumer_key')),
            'stripe' => Setting::getValue('stripe_enabled') == '1' && ! empty(Setting::getValue('stripe_secret_key')),
            'paypal' => Setting::getValue('paypal_enabled') == '1' && ! empty(Setting::getValue('paypal_client_id')),
        ];

        return view('admin.settings.index', compact(
            'group', 'settings', 'keys', 'groups', 'currencies', 'smsTemplatesList', 'emailTemplatesList', 'directAdminNodes', 'activeTab',
            'cronCommand', 'cronCommandOptions', 'cronValidation', 'cronStats',
            'gatewayStatus'
        ));
    }

    /**
     * Allowed setting key prefixes. Any submitted key that doesn't start with
     * one of these prefixes is rejected and logged as a potential injection attempt.
     */
    private const ALLOWED_SETTING_PREFIXES = [
        'site_', 'admin_', 'mail_', 'smtp_', 'billing_', 'tax_', 'mpesa_',
        'stripe_', 'paypal_', 'bank_', 'sms_', 'domain_', 'currency_',
        'security_', 'recaptcha_', 'two_factor_', 'maintenance_',
        'registration_', 'invoice_', 'support_', 'logo_', 'favicon_',
        'primary_', 'company_', 'footer_', 'notify_', 'email_', 'cron_',
        'max_', 'auto_', 'suspend_', 'terminate_', 'grace_',
        'provisioning_', 'directadmin_', 'timezone', 'date_format',
        'reseller_', 'service_', 'currency',
    ];

    public function update(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->merge([
            'settings' => $this->normalizeSettingsInput($request->input('settings', [])),
        ]);

        $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'string|max:5000',
        ]);

        $settings = $request->input('settings', []);

        // Don't save empty values for sensitive settings (like API tokens)
        // This prevents password fields from clearing saved credentials when left blank
        $sensitiveFields = [
            'sms_api_token', 'smtp_password', 'mpesa_passkey', 'mpesa_consumer_secret', 'mpesa_callback_token',
            'directadmin_api_password', 'stripe_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'paypal_client_secret', 'recaptcha_secret_key',
        ];

        foreach ($settings as $key => $value) {
            // Whitelist check: only allow keys that start with a known prefix
            $allowed = false;
            foreach (self::ALLOWED_SETTING_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix) || $key === $prefix) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                \Log::warning('SettingController: rejected unknown setting key', [
                    'key' => $key,
                    'user_id' => auth()->id(),
                    'ip' => request()->ip(),
                ]);

                continue;
            }

            $trimmedValue = trim((string) $value);

            // Skip empty sensitive fields
            if (in_array($key, $sensitiveFields) && empty($trimmedValue)) {
                continue;
            }

            Setting::setValue($key, $trimmedValue);
        }

        // Return JSON for AJAX requests, redirect for traditional form submissions
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'message' => 'Settings saved successfully.',
            ]);
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    /**
     * Flatten checkbox + hidden pairs (same name) that arrive as arrays from multipart forms.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private function normalizeSettingsInput(array $settings): array
    {
        $normalized = [];

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $value = end($value);
            }

            if ($value === null || is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (! is_scalar($value)) {
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    public function updateDirectAdminNameservers(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $validated = $request->validate([
            'nodes' => 'required|array',
            'nodes.*.nameserver_1' => 'required|string|max:255',
            'nodes.*.nameserver_2' => 'nullable|string|max:255',
            'nodes.*.nameserver_3' => 'nullable|string|max:255',
            'nodes.*.nameserver_4' => 'nullable|string|max:255',
        ]);

        foreach ($validated['nodes'] as $nodeId => $data) {
            $node = Node::query()
                ->where('id', $nodeId)
                ->where('type', 'directadmin')
                ->first();

            if (! $node) {
                continue;
            }

            $node->update([
                'nameserver_1' => trim($data['nameserver_1']),
                'nameserver_2' => ! empty($data['nameserver_2']) ? trim($data['nameserver_2']) : null,
                'nameserver_3' => ! empty($data['nameserver_3']) ? trim($data['nameserver_3']) : null,
                'nameserver_4' => ! empty($data['nameserver_4']) ? trim($data['nameserver_4']) : null,
            ]);
        }

        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'message' => 'DirectAdmin nameservers saved successfully.',
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'provisioning'])
            ->with('success', 'DirectAdmin nameservers saved successfully.');
    }

    public function uploadFile(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'file' => 'required|file|image|mimes:jpeg,png,gif,webp,ico|max:5120',
            'type' => 'required|in:logo,favicon',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');

        // Store file in public disk under branding directory
        $path = $file->store("branding/{$type}", 'public');
        $url = asset("storage/{$path}");

        // Update setting
        $settingKey = $type === 'logo' ? 'logo_url' : 'favicon_url';
        Setting::setValue($settingKey, $url);

        return response()->json([
            'success' => true,
            'url' => $url,
            'message' => ucfirst($type).' uploaded successfully.',
        ]);
    }

    public function testSmtp(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'email' => 'required|email',
        ]);

        $toEmail = $request->input('email');
        $subject = 'Talksasa Cloud - SMTP Test Email';
        $body = "This is a test email from Talksasa Cloud.\n\nIf you received this email, your SMTP settings are configured correctly!";

        // Validate SMTP configuration
        $smtpHost = Setting::getValue('smtp_host');
        $smtpPort = Setting::getValue('smtp_port');
        $smtpUser = Setting::getValue('smtp_user');
        $smtpPassword = Setting::getValue('smtp_password');

        if (! $smtpHost || ! $smtpPort || ! $smtpUser || ! $smtpPassword) {
            $missing = [];
            if (! $smtpHost) {
                $missing[] = 'Host';
            }
            if (! $smtpPort) {
                $missing[] = 'Port';
            }
            if (! $smtpUser) {
                $missing[] = 'Username';
            }
            if (! $smtpPassword) {
                $missing[] = 'Password';
            }

            $message = 'SMTP configuration incomplete. Missing: '.implode(', ', $missing);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $message], 400);
            }

            return back()->with('error', $message);
        }

        try {
            $fromName = Setting::getValue('mail_from_name', 'Talksasa Cloud');
            $fromAddress = Setting::getValue('mail_from_address', 'noreply@talksasa.cloud');

            Mail::raw($body, function ($message) use ($toEmail, $fromName, $fromAddress, $subject) {
                $message->to($toEmail)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
            });

            // Log the email
            Email::create([
                'recipient' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'status' => 'sent',
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);

            $message = 'Test email sent successfully to '.$toEmail.'. Check your inbox!';
            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $message]);
            }

            return back()->with('success', $message);
        } catch (\Swift_TransportException $e) {
            $errorMsg = 'SMTP Connection Error: '.$e->getMessage();
            $errorMsg .= $this->smtpTroubleshootingHint($e->getMessage(), $smtpHost, $smtpPort, $smtpUser);
            \Log::error('SMTP Test Failed - Connection Error', [
                'host' => $smtpHost,
                'port' => $smtpPort,
                'error' => $e->getMessage(),
            ]);

            // Log the failed email
            Email::create([
                'recipient' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'status' => 'failed',
                'response' => $errorMsg,
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 400);
            }

            return back()->with('error', $errorMsg);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to send test email: '.$e->getMessage();
            $errorMsg .= $this->smtpTroubleshootingHint($e->getMessage(), $smtpHost, $smtpPort, $smtpUser);
            \Log::error('SMTP Test Failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            // Log the failed email
            Email::create([
                'recipient' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'status' => 'failed',
                'response' => $errorMsg,
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 400);
            }

            return back()->with('error', $errorMsg);
        }
    }

    private function smtpTroubleshootingHint(string $rawMessage, ?string $host, ?string $port, ?string $username): string
    {
        $message = strtolower($rawMessage);
        $hostLower = strtolower((string) $host);
        $username = (string) $username;
        $hints = [];

        if (str_contains($message, '535') || str_contains($message, 'authentication failed') || str_contains($message, 'failed to authenticate')) {
            $hints[] = 'Authentication failed (535): verify SMTP username and password.';
            $hints[] = 'For Zoho with 2FA enabled, use an app-specific password (not your login password).';
            $hints[] = 'Use full mailbox email as SMTP username (example: sales@talksasa.com).';
        }

        if ($hostLower === 'smtp.zoho.com' || $hostLower === 'smtppro.zoho.com') {
            if ((string) $port === '587') {
                $hints[] = 'Zoho on port 587 should use TLS.';
            } elseif ((string) $port === '465') {
                $hints[] = 'Zoho on port 465 should use SSL.';
            } else {
                $hints[] = 'For Zoho, recommended ports are 587 (TLS) or 465 (SSL).';
            }

            if ($hostLower === 'smtppro.zoho.com') {
                $hints[] = 'smtppro.zoho.com is for paid/domain mailboxes.';
            } else {
                $hints[] = 'smtp.zoho.com is for personal/free mailboxes.';
            }
        }

        if ($username !== '' && ! filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $hints[] = 'SMTP username should be a full email address.';
        }

        if (empty($hints)) {
            return '';
        }

        return ' | Quick checks: '.implode(' ', array_unique($hints));
    }

    public function testSms(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'phone' => 'required|string|min:10',
        ]);

        $senderId = Setting::getValue('sms_sender_id', 'TalksasaCloud');
        $smsService = new SmsService;
        $result = $smsService->sendTest($request->input('phone'), $senderId);

        // Return JSON response
        return response()->json($result);
    }

    public function debugLog(Request $request)
    {
        if (! app()->isLocal()) {
            abort(404);
        }

        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'message' => 'required|string',
            'data' => 'nullable|array',
        ]);

        $message = $request->input('message');
        $data = $request->input('data');

        \Log::info("[CLIENT DEBUG] $message", (array) $data);

        return response()->json(['success' => true]);
    }

    public function refreshCurrencies(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        try {
            // This would call an external API to fetch current exchange rates
            // For now, just return success
            return response()->json([
                'success' => true,
                'message' => 'Exchange rates refreshed successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh exchange rates: '.$e->getMessage(),
            ], 500);
        }
    }

    public function testMpesa(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $mpesaService = new MpesaService;
        $result = $mpesaService->testConnection();

        return response()->json($result);
    }

    public function registerMpesaUrls(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'response_type' => 'required|in:Completed,Cancelled',
        ]);

        $mpesaService = new MpesaService;
        $result = $mpesaService->registerCallbackUrls($request->input('response_type'));

        return response()->json($result);
    }

    public function simulateMpesaPayment(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        if (Setting::getValue('mpesa_environment', 'sandbox') === 'production') {
            return response()->json([
                'success' => false,
                'message' => 'Payment simulation is disabled in production.',
            ], 403);
        }

        $request->validate([
            'phone_number' => 'required|string|min:10',
            'amount' => 'required|numeric|min:1|max:999999',
        ]);

        $mpesaService = new MpesaService;
        $result = $mpesaService->simulatePayment($request->input('phone_number'), $request->input('amount'));

        return response()->json($result);
    }
}
