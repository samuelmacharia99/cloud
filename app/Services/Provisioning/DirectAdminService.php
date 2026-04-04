<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DirectAdminService
{
    protected string $apiUrl;
    protected string $username;
    protected string $password;

    public function __construct()
    {
        $this->apiUrl = Setting::getValue('directadmin_api_url', '');
        $this->username = Setting::getValue('directadmin_api_user', 'admin');
        $this->password = Setting::getValue('directadmin_api_password', '');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiUrl) && !empty($this->password);
    }

    /**
     * Create a hosting account on DirectAdmin
     *
     * @param Service $service
     * @param string $username DirectAdmin username
     * @param string $password DirectAdmin password
     * @param string $domain Primary domain for the account
     * @param string $package DirectAdmin package name
     *
     * @return array ['success' => bool, 'message' => string, 'credentials' => array]
     */
    public function createHostingAccount(Service $service, string $username, string $password, string $domain, string $package): array
    {
        try {
            // STUBBED: In production, uncomment and configure with actual DirectAdmin API
            /*
            $response = Http::withBasicAuth($this->username, $this->password)
                ->post("{$this->apiUrl}/CMD_API_ACCOUNT_USER", [
                    'action' => 'create',
                    'username' => $username,
                    'email' => $service->user->email,
                    'passwd' => $password,
                    'domain' => $domain,
                    'package' => $package,
                    'notify' => 'yes',
                ])
                ->throw();

            return [
                'success' => true,
                'message' => 'Hosting account created successfully',
                'credentials' => [
                    'username' => $username,
                    'password' => $password,
                    'domain' => $domain,
                    'package' => $package,
                ],
            ];
            */

            // STUB: Return mock success for now
            return [
                'success' => true,
                'message' => 'Hosting account provisioned (DirectAdmin not configured)',
                'credentials' => [
                    'username' => $username,
                    'password' => $password,
                    'domain' => $domain,
                    'package' => $package,
                    'note' => 'This is a mock response. Configure DirectAdmin credentials in settings.',
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create hosting account: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Suspend a hosting account
     */
    public function suspendAccount(Service $service): bool
    {
        try {
            // STUBBED
            /*
            $reference = $service->external_reference;
            Http::withBasicAuth($this->username, $this->password)
                ->post("{$this->apiUrl}/CMD_API_ACCOUNT_USER", [
                    'action' => 'suspend',
                    'user' => $reference,
                ])
                ->throw();
            */

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to suspend DirectAdmin account: {$e->getMessage()}", [
                'service_id' => $service->id,
            ]);

            return false;
        }
    }

    /**
     * Unsuspend a hosting account
     */
    public function unsuspendAccount(Service $service): bool
    {
        try {
            // STUBBED
            /*
            $reference = $service->external_reference;
            Http::withBasicAuth($this->username, $this->password)
                ->post("{$this->apiUrl}/CMD_API_ACCOUNT_USER", [
                    'action' => 'unsuspend',
                    'user' => $reference,
                ])
                ->throw();
            */

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to unsuspend DirectAdmin account: {$e->getMessage()}", [
                'service_id' => $service->id,
            ]);

            return false;
        }
    }

    /**
     * Terminate a hosting account
     */
    public function terminateAccount(Service $service): bool
    {
        try {
            // STUBBED
            /*
            $reference = $service->external_reference;
            Http::withBasicAuth($this->username, $this->password)
                ->post("{$this->apiUrl}/CMD_API_ACCOUNT_USER", [
                    'action' => 'delete',
                    'user' => $reference,
                    'secure' => 'yes',
                ])
                ->throw();
            */

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to terminate DirectAdmin account: {$e->getMessage()}", [
                'service_id' => $service->id,
            ]);

            return false;
        }
    }
}
