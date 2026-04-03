<?php

namespace App\Http\Controllers\Admin;

use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

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
            'card_enabled', 'stripe_key',
            'bank_transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_number',
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
        $keys = $this->groups[$group] ?? $this->groups['general'];
        $settings = Setting::whereIn('key', $keys)->pluck('value', 'key');
        $groups = $this->groups;

        return view('admin.settings.index', compact('group', 'settings', 'keys', 'groups'));
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
}
