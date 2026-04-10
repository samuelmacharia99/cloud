<?php

namespace App\Http\Controllers\Admin;

use App\Models\Setting;
use App\Models\Currency;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
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
        ],
        'tax' => [
            'tax_enabled', 'tax_rate', 'tax_name', 'tax_inclusive', 'tax_number',
        ],
        'payment_methods' => [
            'mpesa_enabled', 'mpesa_shortcode', 'mpesa_passkey',
            'mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_environment',
            'card_enabled', 'stripe_key',
            'bank_transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_number',
            'bank_branch', 'bank_swift_code',
            'manual_enabled',
        ],
        'provisioning' => [
            'provisioning_mode', 'auto_provision', 'suspend_on_overdue', 'terminate_after_days',
        ],
        'branding' => [
            'logo_url', 'favicon_url', 'primary_color', 'company_name', 'footer_text',
        ],
        'email' => [
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password',
            'mail_from_name', 'mail_from_address',
        ],
        'notifications' => [
            'notify_new_order', 'notify_payment', 'notify_service_suspend', 'notify_ticket',
        ],
        'cron' => [
            'cron_timezone', 'cron_retention_days', 'max_execution_time',
        ],
        'sms' => [
            'sms_enabled', 'sms_api_token', 'sms_sender_id',
        ],
        'directadmin' => [
            'directadmin_api_url', 'directadmin_api_user', 'directadmin_api_password', 'directadmin_default_package',
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

        return view('admin.settings.index', compact('group', 'settings', 'keys', 'groups', 'currencies'));
    }

    public function update(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'string|max:5000',
        ]);

        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            Setting::setValue($key, trim($value));
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    public function uploadFile(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'file' => 'required|image|max:5120', // 5MB max
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
            'message' => ucfirst($type) . ' uploaded successfully.',
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

        try {
            $fromName = Setting::getValue('mail_from_name', 'Talksasa Cloud');
            $fromAddress = Setting::getValue('mail_from_address', 'noreply@talksasa.cloud');

            Mail::raw($body, function ($message) use ($toEmail, $fromName, $fromAddress, $subject) {
                $message->to($toEmail)
                        ->from($fromAddress, $fromName)
                        ->subject($subject);
            });

            // Log the email
            \App\Models\Email::create([
                'recipient' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'status' => 'sent',
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return back()->with('success', 'Test email sent successfully to ' . $toEmail);
        } catch (\Exception $e) {
            // Log the failed email
            \App\Models\Email::create([
                'recipient' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'status' => 'failed',
                'response' => $e->getMessage(),
                'sent_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return back()->with('error', 'Failed to send test email: ' . $e->getMessage());
        }
    }

    public function testSms(Request $request)
    {
        $this->authorize('batchUpdate', Setting::class);

        $request->validate([
            'phone' => 'required|string|min:10',
        ]);

        $senderId = Setting::getValue('sms_sender_id', 'TalksasaCloud');
        $smsService = new \App\Services\SmsService();
        $result = $smsService->sendTest($request->input('phone'), $senderId);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        } else {
            return back()->with('error', $result['message']);
        }
    }
}
