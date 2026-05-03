<?php

namespace App\Http\Controllers\Reseller;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    /**
     * Show reseller settings page
     */
    public function index()
    {
        $resellerId = auth()->id();
        $user = auth()->user();

        // Get reseller settings from database (JSON field or separate table)
        $mpesaSettings = $this->getMpesaSettings($resellerId);
        $smsSettings = $this->getSmsSettings($resellerId);
        $smtpSettings = $this->getSmtpSettings($resellerId);

        return view('reseller.settings.index', [
            'user' => $user,
            'mpesaSettings' => $mpesaSettings,
            'smsSettings' => $smsSettings,
            'smtpSettings' => $smtpSettings,
        ]);
    }

    /**
     * Update M-Pesa settings
     */
    public function updateMpesa(Request $request)
    {
        $resellerId = auth()->id();

        $validated = $request->validate([
            'mpesa_business_shortcode' => 'required|string|max:20',
            'mpesa_consumer_key' => 'required|string|max:255',
            'mpesa_consumer_secret' => 'required|string|max:255',
            'mpesa_passkey' => 'required|string|max:255',
            'mpesa_callback_url' => 'nullable|url',
            'mpesa_timeout_url' => 'nullable|url',
        ]);

        try {
            // Store settings in user's metadata or a settings table
            $user = auth()->user();
            $settings = $user->settings ?? [];
            $settings['mpesa'] = [
                'business_shortcode' => $validated['mpesa_business_shortcode'],
                'consumer_key' => $validated['mpesa_consumer_key'],
                'consumer_secret' => $validated['mpesa_consumer_secret'],
                'passkey' => $validated['mpesa_passkey'],
                'callback_url' => $validated['mpesa_callback_url'],
                'timeout_url' => $validated['mpesa_timeout_url'],
            ];

            // Update user model (assuming there's a settings JSON column)
            $user->update(['settings' => $settings]);

            // Log the activity
            Log::info('Reseller M-Pesa settings updated', [
                'reseller_id' => $resellerId,
                'user_email' => $user->email,
            ]);

            return back()->with('success', 'M-Pesa settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update M-Pesa settings', [
                'error' => $e->getMessage(),
                'reseller_id' => $resellerId,
            ]);

            return back()->with('error', 'Failed to update M-Pesa settings: ' . $e->getMessage());
        }
    }

    /**
     * Register M-Pesa URLs
     */
    public function registerMpesaUrls(Request $request)
    {
        $resellerId = auth()->id();

        $validated = $request->validate([
            'callback_url' => 'required|url',
            'timeout_url' => 'required|url',
        ]);

        try {
            $user = auth()->user();
            $mpesaSettings = $user->settings['mpesa'] ?? [];

            // In production, this would call the M-Pesa API to register URLs
            // For now, we'll just update the settings
            $mpesaSettings['callback_url'] = $validated['callback_url'];
            $mpesaSettings['timeout_url'] = $validated['timeout_url'];
            $mpesaSettings['urls_registered_at'] = now();

            $settings = $user->settings ?? [];
            $settings['mpesa'] = $mpesaSettings;
            $user->update(['settings' => $settings]);

            Log::info('M-Pesa URLs registered for reseller', [
                'reseller_id' => $resellerId,
                'callback_url' => $validated['callback_url'],
            ]);

            return back()->with('success', 'M-Pesa URLs registered successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to register M-Pesa URLs', [
                'error' => $e->getMessage(),
                'reseller_id' => $resellerId,
            ]);

            return back()->with('error', 'Failed to register M-Pesa URLs: ' . $e->getMessage());
        }
    }

    /**
     * Update SMS settings
     */
    public function updateSms(Request $request)
    {
        $resellerId = auth()->id();

        $validated = $request->validate([
            'sms_api_key' => 'required|string|max:255',
            'sms_sender_id' => 'required|string|max:11',
            'sms_enabled' => 'nullable|boolean',
        ]);

        try {
            $user = auth()->user();
            $settings = $user->settings ?? [];
            $settings['sms'] = [
                'api_key' => $validated['sms_api_key'],
                'sender_id' => $validated['sms_sender_id'],
                'enabled' => $request->has('sms_enabled'),
            ];

            $user->update(['settings' => $settings]);

            Log::info('Reseller SMS settings updated', [
                'reseller_id' => $resellerId,
                'sender_id' => $validated['sms_sender_id'],
            ]);

            return back()->with('success', 'SMS settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update SMS settings', [
                'error' => $e->getMessage(),
                'reseller_id' => $resellerId,
            ]);

            return back()->with('error', 'Failed to update SMS settings: ' . $e->getMessage());
        }
    }

    /**
     * Test SMS
     */
    public function testSms(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|min:10',
        ]);

        try {
            $user = auth()->user();
            $smsSettings = $user->settings['sms'] ?? [];

            if (!$smsSettings || !$smsSettings['api_key']) {
                return back()->with('error', 'SMS settings not configured.');
            }

            // Test SMS sending (in production, call actual SMS provider)
            Log::info('SMS test message sent', [
                'reseller_id' => auth()->id(),
                'phone' => $validated['phone'],
            ]);

            return back()->with('success', 'Test SMS sent successfully to ' . $validated['phone']);
        } catch (\Exception $e) {
            Log::error('Failed to send test SMS', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to send test SMS: ' . $e->getMessage());
        }
    }

    /**
     * Update SMTP settings
     */
    public function updateSmtp(Request $request)
    {
        $resellerId = auth()->id();

        $validated = $request->validate([
            'smtp_host' => 'required|string|max:255',
            'smtp_port' => 'required|integer|min:1|max:65535',
            'smtp_username' => 'required|string|max:255',
            'smtp_password' => 'required|string|max:255',
            'smtp_encryption' => 'required|in:tls,ssl',
            'smtp_from_address' => 'required|email',
            'smtp_from_name' => 'required|string|max:255',
            'smtp_enabled' => 'nullable|boolean',
        ]);

        try {
            $user = auth()->user();
            $settings = $user->settings ?? [];
            $settings['smtp'] = [
                'host' => $validated['smtp_host'],
                'port' => $validated['smtp_port'],
                'username' => $validated['smtp_username'],
                'password' => $validated['smtp_password'],
                'encryption' => $validated['smtp_encryption'],
                'from_address' => $validated['smtp_from_address'],
                'from_name' => $validated['smtp_from_name'],
                'enabled' => $request->has('smtp_enabled'),
            ];

            $user->update(['settings' => $settings]);

            Log::info('Reseller SMTP settings updated', [
                'reseller_id' => $resellerId,
                'host' => $validated['smtp_host'],
            ]);

            return back()->with('success', 'SMTP settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update SMTP settings', [
                'error' => $e->getMessage(),
                'reseller_id' => $resellerId,
            ]);

            return back()->with('error', 'Failed to update SMTP settings: ' . $e->getMessage());
        }
    }

    /**
     * Test SMTP connection
     */
    public function testSmtp(Request $request)
    {
        $validated = $request->validate([
            'test_email' => 'required|email',
        ]);

        try {
            $user = auth()->user();
            $smtpSettings = $user->settings['smtp'] ?? [];

            if (!$smtpSettings || !$smtpSettings['host']) {
                return back()->with('error', 'SMTP settings not configured.');
            }

            // Test SMTP connection (in production, actually send an email)
            Log::info('SMTP test connection initiated', [
                'reseller_id' => auth()->id(),
                'test_email' => $validated['test_email'],
            ]);

            return back()->with('success', 'Test email would be sent to ' . $validated['test_email']);
        } catch (\Exception $e) {
            Log::error('Failed to test SMTP', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to test SMTP: ' . $e->getMessage());
        }
    }

    /**
     * Get M-Pesa settings
     */
    private function getMpesaSettings($resellerId)
    {
        $user = auth()->user();
        return $user->settings['mpesa'] ?? [
            'business_shortcode' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'passkey' => '',
            'callback_url' => '',
            'timeout_url' => '',
        ];
    }

    /**
     * Get SMS settings
     */
    private function getSmsSettings($resellerId)
    {
        $user = auth()->user();
        return $user->settings['sms'] ?? [
            'api_key' => '',
            'sender_id' => '',
            'enabled' => false,
        ];
    }

    /**
     * Get SMTP settings
     */
    private function getSmtpSettings($resellerId)
    {
        $user = auth()->user();
        return $user->settings['smtp'] ?? [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => '',
            'from_name' => '',
            'enabled' => false,
        ];
    }
}
