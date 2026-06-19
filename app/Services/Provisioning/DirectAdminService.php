<?php

namespace App\Services\Provisioning;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DirectAdminService
{
    protected string $apiUrl;

    protected string $username;

    protected string $password;

    protected ?Node $node;

    public function __construct(?Node $node = null)
    {
        if ($node) {
            $this->node = $node;
            $this->apiUrl = $node->api_url ?? '';
            $this->username = $node->da_admin_username ?? Setting::getValue('directadmin_api_user', 'admin');
            $this->password = $node->da_login_key ?? Setting::getValue('directadmin_api_password', '');
        } else {
            $this->node = null;
            $this->apiUrl = Setting::getValue('directadmin_api_url', '');
            $this->username = Setting::getValue('directadmin_api_user', 'admin');
            $this->password = Setting::getValue('directadmin_api_password', '');
        }
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->password);
    }

    /**
     * Test DirectAdmin API connection and credentials
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            $message = 'DirectAdmin API not configured. Missing API URL or credentials.';
            Log::warning("DirectAdmin API test: {$message}", [
                'api_url' => $this->apiUrl,
                'username' => $this->username,
                'has_password' => ! empty($this->password),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'hint' => 'Set the DirectAdmin API URL and credentials in the Node settings.',
                'api_url' => $this->apiUrl,
                'endpoint' => 'Not called - missing credentials',
            ];
        }

        $endpoint = "{$this->apiUrl}/CMD_API_PACKAGES_USER";

        Log::info('DirectAdmin API test connection', [
            'endpoint' => $endpoint,
            'username' => $this->username,
            'api_url' => $this->apiUrl,
        ]);

        try {
            $response = $this->httpClient()
                ->timeout(10)
                ->get($endpoint, [
                    'json' => 'yes',
                ])
                ->throw();

            Log::info('DirectAdmin API test successful', [
                'endpoint' => $endpoint,
                'username' => $this->username,
            ]);

            return [
                'success' => true,
                'message' => 'DirectAdmin API connection successful',
                'username' => $this->username,
                'api_url' => $this->apiUrl,
                'endpoint' => $endpoint,
            ];
        } catch (\Exception $e) {
            $statusCode = null;
            if (str_contains($e->getMessage(), 'status code')) {
                preg_match('/status code (\d+)/', $e->getMessage(), $matches);
                $statusCode = $matches[1] ?? null;
            }

            Log::error('DirectAdmin API test failed', [
                'endpoint' => $endpoint,
                'username' => $this->username,
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
            ]);

            $hint = match ($statusCode) {
                401 => 'Invalid DirectAdmin API credentials. Make sure you are using the DirectAdmin ADMIN panel username and password/API token, not a hosting account. Update the Node settings with the correct credentials.',
                403 => 'Access denied. The credentials may not have API access enabled in DirectAdmin.',
                404 => 'The API endpoint is not found. Check the API URL is correct and DirectAdmin is running.',
                default => 'Check if the DirectAdmin server is reachable and the API URL is correct.',
            };

            return [
                'success' => false,
                'message' => "DirectAdmin API connection failed: {$e->getMessage()}",
                'username' => $this->username,
                'api_url' => $this->apiUrl,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'hint' => $hint,
                'details' => 'If you just set up the credentials, make sure you are using the DirectAdmin admin account (the one you use to log into the control panel), not a hosting account username.',
            ];
        }
    }

    /**
     * Get detailed connection diagnostics for debugging
     */
    public function getConnectionDiagnostics(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'api_url' => $this->apiUrl,
            'username' => $this->username,
            'has_password' => ! empty($this->password),
            'node_id' => $this->node?->id,
            'node_name' => $this->node?->name,
        ];
    }

    /**
     * Create a hosting account on DirectAdmin
     *
     * @param  string  $username  DirectAdmin username
     * @param  string  $password  DirectAdmin password
     * @param  string  $domain  Primary domain for the account
     * @param  string  $package  DirectAdmin package name
     * @return array ['success' => bool, 'message' => string, 'credentials' => array]
     */
    /**
     * Create or update a DirectAdmin user package so limits match our catalog record.
     *
     * @return array{success: bool, message: string}
     */
    public function ensureUserPackage(DirectAdminPackage $package, ?string $impersonateUsername = null): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured.'];
        }

        try {
            $payload = array_merge(
                $this->buildUserPackageManagePayload($package),
                [
                    'add' => 'Save',
                    'packagename' => $package->name,
                ],
            );

            $response = $this->httpClient($impersonateUsername)
                ->asForm()
                ->post(rtrim($this->apiUrl, '/').'/CMD_API_MANAGE_USER_PACKAGES', $payload);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            if (! $parsed['success']) {
                Log::error('DirectAdmin package limit sync rejected', [
                    'node_id' => $this->node?->id,
                    'package' => $package->name,
                    'impersonation' => filled($impersonateUsername) ? $this->username.'|'.$impersonateUsername : null,
                    'message' => $parsed['message'],
                ]);
            } else {
                Log::info('DirectAdmin package limits synced', [
                    'node_id' => $this->node?->id,
                    'package' => $package->name,
                    'disk_quota_gb' => $package->disk_quota,
                    'bandwidth_quota_gb' => $package->bandwidth_quota,
                ]);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Failed to sync DirectAdmin package limits', [
                'node_id' => $this->node?->id,
                'package' => $package->name,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to sync package limits: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, string|int>
     */
    public function buildUserPackageManagePayload(DirectAdminPackage $package): array
    {
        $payload = array_merge(
            $this->megabyteLimitFields('quota', 'uquota', (float) $package->disk_quota),
            $this->megabyteLimitFields('bandwidth', 'ubandwidth', (float) ($package->bandwidth_quota ?? 0)),
            $this->quantityLimitFields('vdomains', 'uvdomains', $package->num_domains),
            $this->quantityLimitFields('ftp', 'uftp', $package->num_ftp),
            $this->quantityLimitFields('mysql', 'umysql', $package->num_databases),
            $this->quantityLimitFields('nemails', 'unemails', $package->num_email_accounts),
            $this->quantityLimitFields('nsubdomains', 'unsubdomains', $package->num_subdomains),
        );

        $features = $package->features ?? [];

        return array_merge($payload, [
            'php' => ($features['php'] ?? true) ? 'ON' : 'OFF',
            'ssl' => ($features['ssl'] ?? true) ? 'ON' : 'OFF',
            'cgi' => 'ON',
            'dnscontrol' => 'ON',
            'cron' => ($features['cron_jobs'] ?? true) ? 'ON' : 'OFF',
            'suspend_at_limit' => 'ON',
        ]);
    }

    public function createHostingAccount(
        Service $service,
        string $username,
        string $password,
        string $domain,
        string $package,
        ?string $ownerResellerUsername = null,
    ): array {
        try {
            $endpoint = rtrim($this->apiUrl, '/').'/CMD_API_ACCOUNT_USER';

            $payload = [
                'action' => 'create',
                'username' => $username,
                'email' => $service->user->email,
                'passwd' => $password,
                'passwd2' => $password,
                'domain' => $domain,
                'package' => $package,
            ];

            // DirectAdmin assigns new users to the authenticated account owner.
            // Admin must impersonate the target reseller (admin|reseller) — a payload
            // "reseller" field is not supported on CMD_API_ACCOUNT_USER.
            $response = $this->httpClient($ownerResellerUsername)
                ->asForm()
                ->post($endpoint, $payload);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            if (! $parsed['success']) {
                Log::error('DirectAdmin account creation rejected', [
                    'service_id' => $service->id,
                    'username' => $username,
                    'domain' => $domain,
                    'package' => $package,
                    'response' => $parsed['message'],
                ]);

                return [
                    'success' => false,
                    'message' => $parsed['message'],
                ];
            }

            Log::info("DirectAdmin account created: {$username}", [
                'service_id' => $service->id,
                'domain' => $domain,
                'package' => $package,
                'owner_reseller' => $ownerResellerUsername,
            ]);

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
        } catch (\Exception $e) {
            Log::error("Failed to create DirectAdmin account: {$e->getMessage()}", [
                'service_id' => $service->id,
                'username' => $username,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create hosting account: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Reassign a hosting account to a different DirectAdmin reseller (admin API).
     * Pass null to move the account back under admin/platform ownership.
     *
     * @return array{success: bool, message: string}
     */
    public function reassignUserReseller(string $username, ?string $newResellerUsername): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured.'];
        }

        if (blank($username)) {
            return ['success' => false, 'message' => 'Hosting username is required.'];
        }

        try {
            $payload = [
                'action' => 'customize',
                'user' => $username,
                'reseller' => filled($newResellerUsername) ? $newResellerUsername : $this->username,
            ];

            $response = $this->httpClient()
                ->asForm()
                ->post(rtrim($this->apiUrl, '/').'/CMD_API_MODIFY_USER', $payload);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            if ($parsed['success']) {
                Log::info('DirectAdmin user reassigned to reseller', [
                    'username' => $username,
                    'reseller' => $payload['reseller'],
                    'node_id' => $this->node?->id,
                ]);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Failed to reassign DirectAdmin user reseller', [
                'username' => $username,
                'new_reseller' => $newResellerUsername,
                'node_id' => $this->node?->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to reassign hosting account: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Change the hosting package assigned to an existing DirectAdmin user.
     *
     * @return array{success: bool, message: string}
     */
    public function changeUserPackage(string $username, string $packageKey): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured.'];
        }

        if (blank($username) || blank($packageKey)) {
            return ['success' => false, 'message' => 'Username and package are required.'];
        }

        try {
            $response = $this->httpClient()
                ->asForm()
                ->post(rtrim($this->apiUrl, '/').'/CMD_API_MODIFY_USER', [
                    'action' => 'package',
                    'user' => $username,
                    'package' => $packageKey,
                ]);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            if ($parsed['success']) {
                Log::info('DirectAdmin user package changed', [
                    'username' => $username,
                    'package' => $packageKey,
                    'node_id' => $this->node?->id,
                ]);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Failed to change DirectAdmin user package', [
                'username' => $username,
                'package' => $packageKey,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to change hosting package: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check whether a DirectAdmin user already exists.
     */
    public function accountExists(string $username): bool
    {
        try {
            $endpoint = rtrim($this->apiUrl, '/').'/CMD_API_SHOW_USER_CONFIG';

            $response = $this->httpClient()
                ->get($endpoint, [
                    'user' => $username,
                ]);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            return $parsed['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Read the current DirectAdmin account state for live status reconciliation.
     *
     * @return array{live_status: string, label: string, detail: array<string, mixed>}
     */
    public function getAccountLiveStatus(string $username): array
    {
        if (! $this->isConfigured() || blank($username)) {
            return [
                'live_status' => 'unavailable',
                'label' => 'DirectAdmin API not configured',
                'detail' => [],
            ];
        }

        if (! $this->accountExists($username)) {
            return [
                'live_status' => 'terminated',
                'label' => 'Account not found on DirectAdmin',
                'detail' => ['username' => $username],
            ];
        }

        $config = $this->executeAdminApiCall('CMD_API_SHOW_USER_CONFIG', ['user' => $username]);
        if (! $config['success']) {
            return [
                'live_status' => 'unknown',
                'label' => $config['message'] ?? 'Could not read DirectAdmin account',
                'detail' => [],
            ];
        }

        $data = $config['data'];
        $suspended = $this->isDirectAdminSuspendedFlag($data['suspended'] ?? null);

        return [
            'live_status' => $suspended ? 'suspended' : 'active',
            'label' => $suspended ? 'Suspended on DirectAdmin' : 'Active on DirectAdmin',
            'detail' => [
                'username' => $username,
                'suspended' => $data['suspended'] ?? null,
                'domain' => $data['domain'] ?? null,
                'package' => $data['package'] ?? null,
            ],
        ];
    }

    private function isDirectAdminSuspendedFlag(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', '1', 'on', 'true'], true);
    }

    /**
     * @return array{used_mb: float, limit_mb: ?float}|null
     */
    public function getAccountDiskUsage(string $username): ?array
    {
        if (! $this->isConfigured() || blank($username)) {
            return null;
        }

        $config = $this->executeAdminApiCall('CMD_API_SHOW_USER_CONFIG', ['user' => $username]);
        if (! $config['success']) {
            return null;
        }

        $stats = $this->executeAdminApiCall('CMD_API_USER_STATS', ['user' => $username]);
        $usage = $stats['success'] ? $stats['data'] : [];

        $diskQuota = $config['data']['quota'] ?? $config['data']['disk'] ?? $usage['quota'] ?? null;
        $diskUsed = $usage['quota_used'] ?? $config['data']['quota_used'] ?? $usage['disk'] ?? null;

        $usedMb = $this->toMegabytes($diskUsed);
        $limitMb = $this->toMegabytes($diskQuota);

        if ($usedMb === null) {
            return null;
        }

        return [
            'used_mb' => $usedMb,
            'limit_mb' => $limitMb,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function parseApiResponse(string $body, int $statusCode): array
    {
        if ($statusCode >= 400) {
            return [
                'success' => false,
                'message' => "DirectAdmin API HTTP {$statusCode}: ".trim($body),
            ];
        }

        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false) {
            return [
                'success' => false,
                'message' => 'DirectAdmin returned HTML instead of an API response. Check API credentials and permissions.',
            ];
        }

        $trimmed = trim($body);

        if ($trimmed === '') {
            return [
                'success' => false,
                'message' => 'DirectAdmin API returned an empty response.',
            ];
        }

        if (str_contains($trimmed, 'error=0') || str_contains($trimmed, '"error":"0"')) {
            return ['success' => true, 'message' => 'OK'];
        }

        if (preg_match('/error=1(?:&|$)/', $trimmed) || str_contains($trimmed, '"error":"1"')) {
            if (preg_match('/text=([^&\n]+)/', $trimmed, $matches)) {
                return [
                    'success' => false,
                    'message' => urldecode($matches[1]),
                ];
            }

            $json = json_decode($trimmed, true);
            if (is_array($json) && ! empty($json['text'])) {
                return [
                    'success' => false,
                    'message' => (string) $json['text'],
                ];
            }

            return [
                'success' => false,
                'message' => 'DirectAdmin rejected the request: '.$trimmed,
            ];
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            if (isset($json['error']) && (string) $json['error'] !== '0') {
                return [
                    'success' => false,
                    'message' => (string) ($json['text'] ?? $json['result'] ?? $trimmed),
                ];
            }
        }

        return ['success' => true, 'message' => 'OK'];
    }

    private function httpClient(?string $impersonateUsername = null)
    {
        $authUsername = $this->username;
        if (filled($impersonateUsername)) {
            $authUsername = $authUsername.'|'.$impersonateUsername;
        }

        $request = Http::timeout(30)->withBasicAuth($authUsername, $this->password);

        if ($this->node && ($this->node->verify_ssl ?? true) === false) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    /**
     * Suspend a hosting account using correct DirectAdmin API
     */
    public function suspendAccount(Service $service): bool
    {
        $username = $this->resolveDirectAdminUsername($service);

        return $username ? $this->suspendUserByUsername($username, $service->id) : false;
    }

    /**
     * Unsuspend a hosting account using correct DirectAdmin API
     */
    public function unsuspendAccount(Service $service): bool
    {
        $username = $this->resolveDirectAdminUsername($service);

        return $username ? $this->unsuspendUserByUsername($username, $service->id) : false;
    }

    public function suspendUserByUsername(string $username, ?int $contextId = null): bool
    {
        return $this->selectUserActionByUsername($username, 'Suspend', 'SUSPEND', $contextId);
    }

    public function unsuspendUserByUsername(string $username, ?int $contextId = null): bool
    {
        return $this->selectUserActionByUsername($username, 'Unsuspend', 'UNSUSPEND', $contextId);
    }

    /**
     * Count end-user accounts created under a DirectAdmin reseller account.
     */
    public function countUsersOwnedByReseller(string $resellerUsername): ?int
    {
        $users = $this->listUsersOwnedByReseller($resellerUsername);

        return $users === null ? null : count($users);
    }

    /**
     * @return list<string>|null
     */
    public function listUsersOwnedByReseller(string $resellerUsername): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_SHOW_USERS', [
                    'reseller' => $resellerUsername,
                    'json' => 'yes',
                ]);

            if ($response->status() >= 400) {
                Log::warning('DirectAdmin SHOW_USERS failed', [
                    'reseller' => $resellerUsername,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $this->parseUserListResponse($response->body());
        } catch (\Exception $e) {
            Log::error('DirectAdmin SHOW_USERS exception', [
                'reseller' => $resellerUsername,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Terminate a hosting account on DirectAdmin
     */
    public function terminateAccount(Service $service): bool
    {
        $username = $this->resolveDirectAdminUsername($service);

        if (! $username) {
            Log::error("TERMINATE_FAILED: No username for service {$service->id}");

            return false;
        }

        $endpoint = rtrim($this->apiUrl, '/').'/CMD_API_ACCOUNT_USER';

        Log::info('TERMINATE_API_CALL', [
            'service_id' => $service->id,
            'username' => $username,
            'endpoint' => $endpoint,
        ]);

        try {
            $response = $this->httpClient()
                ->asForm()
                ->post($endpoint, [
                    'action' => 'delete',
                    'delete' => 'yes',
                    'confirmed' => 'Confirm',
                    'user' => $username,
                ]);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            Log::info('TERMINATE_API_RESPONSE', [
                'service_id' => $service->id,
                'status' => $response->status(),
                'success' => $parsed['success'],
                'message' => $parsed['message'],
            ]);

            if (! $parsed['success']) {
                Log::error('TERMINATE_API_ERROR', [
                    'service_id' => $service->id,
                    'username' => $username,
                    'message' => $parsed['message'],
                ]);
            }

            return $parsed['success'];
        } catch (\Exception $e) {
            Log::error('TERMINATE_EXCEPTION', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function selectUserActionByUsername(string $username, string $action, string $logPrefix, ?int $contextId = null): bool
    {
        $endpoint = rtrim($this->apiUrl, '/').'/CMD_API_SELECT_USERS';

        Log::info("{$logPrefix}_API_CALL", [
            'context_id' => $contextId,
            'username' => $username,
            'endpoint' => $endpoint,
        ]);

        try {
            $response = $this->httpClient()
                ->asForm()
                ->post($endpoint, [
                    'location' => 'CMD_SELECT_USERS',
                    'suspend' => $action,
                    'select0' => $username,
                ]);

            $parsed = $this->parseApiResponse($response->body(), $response->status());

            Log::info("{$logPrefix}_API_RESPONSE", [
                'context_id' => $contextId,
                'status' => $response->status(),
                'success' => $parsed['success'],
                'message' => $parsed['message'],
            ]);

            if (! $parsed['success']) {
                Log::error("{$logPrefix}_API_ERROR", [
                    'context_id' => $contextId,
                    'username' => $username,
                    'message' => $parsed['message'],
                ]);
            }

            return $parsed['success'];
        } catch (\Exception $e) {
            Log::error("{$logPrefix}_EXCEPTION", [
                'context_id' => $contextId,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function parseUserListResponse(string $body): array
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return [];
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            if (isset($json['error']) && (string) $json['error'] !== '0') {
                return [];
            }

            if (isset($json['list']) && is_array($json['list'])) {
                return array_values(array_filter(array_map('strval', $json['list'])));
            }

            if ($this->isListOfUsernames($json)) {
                return array_values(array_filter(array_map('strval', $json)));
            }
        }

        parse_str($trimmed, $parsed);

        if (isset($parsed['list']) && is_array($parsed['list'])) {
            return array_values(array_filter(array_map('strval', $parsed['list'])));
        }

        if (preg_match_all('/list\[\]=(\w+)/', $trimmed, $matches)) {
            return $matches[1];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function isListOfUsernames(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        foreach ($data as $key => $value) {
            if (! is_int($key) && ! is_numeric((string) $key)) {
                return false;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    private function resolveDirectAdminUsername(Service $service): ?string
    {
        return $service->external_reference ?? ($service->service_meta['username'] ?? null);
    }

    /**
     * Fetch user hosting packages owned by a DirectAdmin reseller account.
     *
     * Uses admin login-as impersonation (admin|reseller) per DirectAdmin API docs —
     * a plain CMD_API_PACKAGES_USER call as admin returns only the admin's packages.
     *
     * @return list<array{name: string, package_key: string, description: ?string, disk_quota: float, bandwidth_quota: float}>
     */
    public function getResellerPackages(string $resellerUsername): array
    {
        if (! $this->isConfigured() || blank($resellerUsername)) {
            return [];
        }

        try {
            $response = $this->httpClient($resellerUsername)
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_PACKAGES_USER', [
                    'json' => 'yes',
                ])
                ->throw();

            if (! $response->ok()) {
                Log::warning('DirectAdmin PACKAGES_USER (reseller impersonation) request failed', [
                    'node_id' => $this->node?->id,
                    'reseller' => $resellerUsername,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $body = $response->body();
            if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false) {
                Log::warning('DirectAdmin reseller package list returned HTML', [
                    'node_id' => $this->node?->id,
                    'reseller' => $resellerUsername,
                    'body_preview' => substr($body, 0, 500),
                ]);

                return [];
            }

            $packageNames = $this->parsePackageNameList($body, $response->json());
            $packages = [];

            foreach ($packageNames as $packageName) {
                $details = $this->getPackageDetails($packageName, $resellerUsername);
                $packages[] = $details ?? [
                    'name' => $packageName,
                    'package_key' => Str::slug($packageName),
                    'description' => null,
                    'disk_quota' => 0,
                    'bandwidth_quota' => 0,
                ];
            }

            Log::info('Fetched DirectAdmin reseller user packages', [
                'node_id' => $this->node?->id,
                'reseller' => $resellerUsername,
                'impersonation' => $this->username.'|'.$resellerUsername,
                'package_count' => count($packages),
            ]);

            return $packages;
        } catch (\Exception $e) {
            Log::error('Failed to fetch DirectAdmin reseller user packages', [
                'node_id' => $this->node?->id,
                'reseller' => $resellerUsername,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch admin-level reseller packages from DirectAdmin (CMD_API_PACKAGES_RESELLER).
     *
     * @return list<array<string, mixed>>
     */
    public function getAdminResellerPackages(): array
    {
        if (! $this->isConfigured()) {
            Log::warning('DirectAdmin not configured', ['node_id' => $this->node?->id]);

            return [];
        }

        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_PACKAGES_RESELLER', [
                    'json' => 'yes',
                ])
                ->throw();

            if (! $response->ok()) {
                return [];
            }

            $body = $response->body();

            if (empty($body) || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false) {
                Log::warning('DirectAdmin reseller package list unavailable', [
                    'node_id' => $this->node?->id,
                    'body_preview' => substr($body, 0, 300),
                ]);

                return [];
            }

            $packageNames = $this->parsePackageNameList($body, $response->json());
            $packages = [];

            foreach ($packageNames as $packageName) {
                $details = $this->getResellerPackageDetails($packageName);
                if ($details) {
                    $packages[] = $details;
                }
            }

            Log::info('Fetched DirectAdmin admin reseller packages', [
                'node_id' => $this->node?->id,
                'package_count' => count($packages),
            ]);

            return $packages;
        } catch (\Exception $e) {
            Log::error('Failed to fetch DirectAdmin admin reseller packages', [
                'node_id' => $this->node?->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getResellerPackageDetails(string $packageName): ?array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_PACKAGES_RESELLER', [
                    'package' => $packageName,
                    'json' => 'yes',
                ])
                ->throw();

            if (! $response->ok()) {
                return null;
            }

            $packageData = $response->json();
            if (! is_array($packageData)) {
                return null;
            }

            return $this->parseResellerPackageData($packageName, $packageData);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch DirectAdmin reseller package details', [
                'node_id' => $this->node?->id,
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResellerPackageData(string $packageName, array $data): array
    {
        return [
            'name' => $packageName,
            'package_key' => Str::slug($packageName),
            'disk_quota' => $this->parseResellerQuotaGb($data['quota'] ?? null),
            'bandwidth_quota' => $this->parseResellerQuotaGb($data['bandwidth'] ?? null),
            'num_domains' => $this->parseResellerQuantity($data['vdomains'] ?? null),
            'num_ftp' => $this->parseResellerQuantity($data['ftp'] ?? null),
            'num_email_accounts' => $this->parseResellerQuantity($data['nemails'] ?? null),
            'num_databases' => $this->parseResellerQuantity($data['mysql'] ?? null),
            'num_subdomains' => $this->parseResellerQuantity($data['nsubdomains'] ?? null),
            'num_ips' => $this->parseResellerQuantity($data['ips'] ?? null),
            'features' => [
                'ssl' => $this->isDirectAdminFeatureOn($data['ssl'] ?? null),
                'ssh' => $this->isDirectAdminFeatureOn($data['ssh'] ?? null),
                'dnscontrol' => $this->isDirectAdminFeatureOn($data['dnscontrol'] ?? null),
                'serverip' => $this->isDirectAdminFeatureOn($data['serverip'] ?? null),
            ],
        ];
    }

    private function parseResellerQuotaGb(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.00;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['unlimited', '-1'], true)) {
            return -1.00;
        }

        if (is_numeric($normalized)) {
            return round(((float) $normalized) / 1024, 2);
        }

        return $this->convertToGb((string) $value);
    }

    private function parseResellerQuantity(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['unlimited', '-1'], true)) {
            return -1;
        }

        return (int) $value;
    }

    private function isDirectAdminFeatureOn(mixed $value): bool
    {
        return in_array(strtoupper(trim((string) ($value ?? ''))), ['ON', 'YES', '1'], true);
    }

    /**
     * Fetch all packages from DirectAdmin server
     * DirectAdmin returns form-encoded list of package names: list[]=Package1&list[]=Package2
     *
     * @return array Array of packages with their specs, or empty array on failure
     */
    public function getPackages(): array
    {
        if (! $this->isConfigured()) {
            Log::warning('DirectAdmin not configured', ['node_id' => $this->node?->id]);

            return [];
        }

        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_PACKAGES_USER', [
                    'json' => 'yes',
                ])
                ->throw();

            $packages = [];

            if ($response->ok()) {
                $body = $response->body();

                if (empty($body)) {
                    Log::warning('DirectAdmin API returned empty response', [
                        'node_id' => $this->node?->id,
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                // Check if response is HTML (error/login page) instead of JSON
                if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false) {
                    Log::warning('DirectAdmin API returned HTML instead of JSON', [
                        'node_id' => $this->node?->id,
                        'status' => $response->status(),
                        'body_preview' => substr($body, 0, 500),
                        'likely_issue' => 'Login key may lack CMD_API_PACKAGES_USER permission',
                    ]);

                    return [];
                }

                // Parse JSON response (can be direct array or object with list key)
                $responseData = $response->json();
                if (! $responseData) {
                    Log::warning('DirectAdmin API returned invalid JSON', [
                        'node_id' => $this->node?->id,
                        'body_preview' => substr($body, 0, 200),
                    ]);

                    return [];
                }

                $packageNames = $this->parsePackageNameList($response->body(), $responseData);

                Log::debug('DirectAdmin API response', [
                    'node_id' => $this->node?->id,
                    'package_count' => count($packageNames),
                    'package_names' => $packageNames,
                ]);

                // Fetch details for each package
                foreach ($packageNames as $packageName) {
                    $packageDetails = $this->getPackageDetails($packageName);
                    if ($packageDetails) {
                        $packages[] = $packageDetails;
                    }
                }
            }

            Log::info('Successfully fetched DirectAdmin packages', [
                'node_id' => $this->node?->id,
                'package_count' => count($packages),
            ]);

            return $packages;
        } catch (\Exception $e) {
            Log::error('Failed to fetch DirectAdmin packages', [
                'node_id' => $this->node?->id,
                'error' => $e->getMessage(),
                'api_url' => $this->apiUrl,
            ]);

            return [];
        }
    }

    /**
     * Fetch detailed package information from DirectAdmin
     * Use CMD_API_PACKAGES_USER with package parameter to get specific package details
     */
    private function getPackageDetails(string $packageName, ?string $impersonateUsername = null): ?array
    {
        try {
            $response = $this->httpClient($impersonateUsername)
                ->get(rtrim($this->apiUrl, '/').'/CMD_API_PACKAGES_USER', [
                    'package' => $packageName,
                    'json' => 'yes',
                ])
                ->throw();

            if (! $response->ok()) {
                Log::warning('Failed to fetch package details - API error', [
                    'node_id' => $this->node?->id,
                    'package_name' => $packageName,
                    'impersonation' => filled($impersonateUsername) ? $this->username.'|'.$impersonateUsername : null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $packageData = $response->json();
            if (! $packageData) {
                Log::warning('Failed to parse package details JSON', [
                    'node_id' => $this->node?->id,
                    'package_name' => $packageName,
                    'impersonation' => filled($impersonateUsername) ? $this->username.'|'.$impersonateUsername : null,
                ]);

                return null;
            }

            return $this->parsePackageData($packageName, $packageData);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch package details', [
                'node_id' => $this->node?->id,
                'package_name' => $packageName,
                'impersonation' => filled($impersonateUsername) ? $this->username.'|'.$impersonateUsername : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function parsePackageNameList(string $body, mixed $responseData): array
    {
        if (! is_array($responseData)) {
            return [];
        }

        if (isset($responseData['list'])) {
            $packageNames = $responseData['list'];
        } elseif (isset($responseData[0]) && is_string($responseData[0])) {
            $packageNames = $responseData;
        } else {
            $packageNames = array_keys(array_filter(
                $responseData,
                fn ($value, $key) => is_string($key) && ! in_array($key, ['error', 'text', 'details'], true),
                ARRAY_FILTER_USE_BOTH
            ));
        }

        if (! is_array($packageNames)) {
            $packageNames = [$packageNames];
        }

        return array_values(array_filter(array_map(
            fn ($name) => is_string($name) ? trim($name) : null,
            $packageNames
        )));
    }

    /**
     * Parse DirectAdmin package data into standardized format.
     *
     * DirectAdmin CMD_API_PACKAGES_USER returns fields like quota, bandwidth,
     * vdomains, nemails, nsubdomains, ftp, mysql (not domainptr/email aliases).
     */
    private function parsePackageData(string $packageName, array $data): array
    {
        return [
            'name' => $packageName,
            'package_key' => Str::slug($packageName),
            'description' => $data['description'] ?? null,
            'disk_quota' => $this->parseQuotaGb($data, 'quota', 'uquota', 'disk'),
            'bandwidth_quota' => $this->parseQuotaGb($data, 'bandwidth', 'ubandwidth'),
            'num_domains' => $this->parseQuantity($data, 'vdomains', 'uvdomains', 'domainptr'),
            'num_ftp' => $this->parseQuantity($data, 'ftp', 'uftp'),
            'num_email_accounts' => $this->parseQuantity($data, 'nemails', 'unemails', 'email'),
            'num_databases' => $this->parseQuantity($data, 'mysql', 'umysql'),
            'num_subdomains' => $this->parseSubdomainsQuantity($data),
            'features' => $this->extractFeatures($data),
        ];
    }

    /**
     * @param  string  ...$fallbackFields  Alternate DirectAdmin field names
     */
    private function parseQuantity(array $data, string $quantityField, string $unlimitedField, string ...$fallbackFields): int
    {
        if ($this->isDirectAdminUnlimited($data[$unlimitedField] ?? null)) {
            return -1;
        }

        foreach ([$quantityField, ...$fallbackFields] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = strtolower(trim((string) $data[$field]));

            if (in_array($value, ['unlimited', '-1'], true)) {
                return -1;
            }

            if ($value !== '') {
                return (int) $value;
            }
        }

        return 0;
    }

    private function parseQuotaGb(array $data, string $quantityField, string $unlimitedField, string ...$fallbackFields): float
    {
        if ($this->isDirectAdminUnlimited($data[$unlimitedField] ?? null)) {
            return -1.00;
        }

        foreach ([$quantityField, ...$fallbackFields] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }

            return $this->convertToGb((string) $data[$field]);
        }

        return 0.00;
    }

    private function parseSubdomainsQuantity(array $data): int
    {
        if ($this->isDirectAdminUnlimited($data['unsubdomains'] ?? null)) {
            return -1;
        }

        if (array_key_exists('nsubdomains', $data)) {
            return $this->parseQuantity($data, 'nsubdomains', 'unsubdomains');
        }

        return $this->parseSubdomains((string) ($data['subdomains'] ?? '0'));
    }

    private function isDirectAdminUnlimited(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $normalized = strtoupper(trim((string) $value));

        return in_array($normalized, ['ON', 'YES', 'UNLIMITED', '-1'], true);
    }

    /**
     * Convert DirectAdmin values (e.g., "100M", "1G") to GB
     */
    private function convertToGb(string $value): float
    {
        $value = strtoupper(trim($value));

        if ($value === 'unlimited' || $value === '-1') {
            return -1.00;
        }

        preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT])?B?$/i', $value, $matches);

        $number = (float) ($matches[1] ?? 0);
        $unit = strtoupper($matches[2] ?? 'M');

        return match ($unit) {
            'K' => $number / 1024 / 1024,
            'M' => $number / 1024,
            'G' => $number,
            'T' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Parse subdomains value (-1 = unlimited)
     */
    private function parseSubdomains(string $value): int
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'unlimited', '-1' => -1,
            default => (int) $value,
        };
    }

    /**
     * Extract features from DirectAdmin package data
     */
    private function extractFeatures(array $data): array
    {
        return [
            'php' => isset($data['php']),
            'cron_jobs' => isset($data['cron']),
            'ssl' => isset($data['ssl']),
            'git' => isset($data['git']),
            'node' => isset($data['nodejs']) || isset($data['node']),
            'python' => isset($data['python']),
            'ruby' => isset($data['ruby']),
            'api_access' => isset($data['api']),
            'backup' => isset($data['backup']),
        ];
    }

    /**
     * Sync packages from DirectAdmin into the database
     * Uses database transaction to ensure all-or-nothing persistence
     *
     * @return array ['synced' => int, 'updated' => int, 'failed' => int, 'errors' => array]
     */
    public function syncPackages(): array
    {
        Log::info('Starting DirectAdmin package sync', ['node_id' => $this->node?->id, 'node_name' => $this->node?->name]);

        $packages = $this->getPackages();
        $result = ['synced' => 0, 'updated' => 0, 'failed' => 0, 'deactivated' => 0, 'errors' => []];

        if (empty($packages)) {
            $result['errors'][] = 'No packages retrieved from DirectAdmin server (no packages defined on server)';
            Log::warning('No packages synced from DirectAdmin', ['node_id' => $this->node?->id]);

            return $result;
        }

        Log::info('Retrieved packages from API', [
            'node_id' => $this->node?->id,
            'count' => count($packages),
            'package_keys' => array_column($packages, 'package_key'),
        ]);

        // Use transaction to ensure data integrity
        return \DB::transaction(function () use ($packages, $result) {
            $syncedKeys = [];

            foreach ($packages as $packageData) {
                try {
                    $syncedKeys[] = $packageData['package_key'];

                    // Query by BOTH node_id AND package_key to avoid overwriting packages from other nodes
                    $existing = DirectAdminPackage::where('node_id', $this->node?->id)
                        ->where('package_key', $packageData['package_key'])
                        ->first();

                    $attributes = array_merge($packageData, [
                        'node_id' => $this->node?->id,
                        'is_active' => true,
                    ]);

                    if ($existing) {
                        $existing->fill($attributes);

                        if ($existing->isDirty()) {
                            $existing->save();
                        } else {
                            $existing->touch();
                        }

                        $result['updated']++;
                        Log::debug('Updated DirectAdmin package', [
                            'node_id' => $this->node?->id,
                            'package_key' => $packageData['package_key'],
                            'package_name' => $packageData['name'],
                        ]);
                    } else {
                        $created = DirectAdminPackage::create($attributes);

                        if ($created && $created->id) {
                            $result['synced']++;
                            Log::debug('Created DirectAdmin package', [
                                'node_id' => $this->node?->id,
                                'package_key' => $packageData['package_key'],
                                'package_name' => $packageData['name'],
                                'package_id' => $created->id,
                            ]);
                        } else {
                            $result['failed']++;
                            $result['errors'][] = "Failed to create {$packageData['package_key']}: Create returned false";
                        }
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $errorMsg = "Failed to sync {$packageData['package_key']}: {$e->getMessage()}";
                    $result['errors'][] = $errorMsg;
                    Log::error('Failed to sync DirectAdmin package', [
                        'node_id' => $this->node?->id,
                        'package_key' => $packageData['package_key'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $deactivated = DirectAdminPackage::query()
                ->where('node_id', $this->node?->id)
                ->where('is_active', true)
                ->whereNotIn('package_key', $syncedKeys)
                ->update(['is_active' => false]);

            $result['deactivated'] = $deactivated;

            Log::info('DirectAdmin package sync completed', [
                'node_id' => $this->node?->id,
                'node_name' => $this->node?->name,
                'synced' => $result['synced'],
                'updated' => $result['updated'],
                'deactivated' => $deactivated,
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ]);

            return $result;
        });
    }

    /**
     * Execute a DirectAdmin legacy API command impersonating an end-user account.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function executeUserApiCall(string $username, string $command, array $params = [], string $httpMethod = 'GET'): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured.', 'data' => []];
        }

        $url = rtrim($this->apiUrl, '/').'/'.ltrim($command, '/');

        try {
            $client = $this->httpClient($username);
            $response = match (strtoupper($httpMethod)) {
                'POST' => $client->asForm()->post($url, $params),
                default => $client->get($url, array_merge($params, ['json' => 'yes'])),
            };

            return $this->decodeUserApiResponse($response->body(), $response->status());
        } catch (\Throwable $e) {
            Log::error('DirectAdmin user API call failed', [
                'node_id' => $this->node?->id,
                'username' => $username,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Execute a DirectAdmin legacy API command as the platform admin.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function executeAdminApiCall(string $command, array $params = [], string $httpMethod = 'GET'): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured.', 'data' => []];
        }

        $url = rtrim($this->apiUrl, '/').'/'.ltrim($command, '/');

        try {
            $client = $this->httpClient();
            $response = match (strtoupper($httpMethod)) {
                'POST' => $client->asForm()->post($url, $params),
                default => $client->get($url, array_merge($params, ['json' => 'yes'])),
            };

            return $this->decodeUserApiResponse($response->body(), $response->status());
        } catch (\Throwable $e) {
            Log::error('DirectAdmin admin API call failed', [
                'node_id' => $this->node?->id,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    private function decodeUserApiResponse(string $body, int $status): array
    {
        $parsed = $this->parseApiResponse($body, $status);
        if (! $parsed['success']) {
            return ['success' => false, 'message' => $parsed['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => $parsed['message'],
            'data' => $this->extractResponseData($body),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractResponseData(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            unset($json['error'], $json['text']);

            return $json;
        }

        parse_str($trimmed, $parsed);

        if (! is_array($parsed)) {
            return [];
        }

        unset($parsed['error'], $parsed['text']);

        return $parsed;
    }

    /**
     * @return array<string, string|int>
     */
    private function megabyteLimitFields(string $quantityField, string $unlimitedField, float $gigabytes): array
    {
        if ($gigabytes < 0) {
            return [$unlimitedField => 'ON'];
        }

        return [
            $quantityField => max(1, (int) round($gigabytes * 1024)),
            $unlimitedField => 'OFF',
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function quantityLimitFields(string $quantityField, string $unlimitedField, ?int $value): array
    {
        if ($value === null || $value < 0) {
            return [$unlimitedField => 'ON'];
        }

        return [
            $quantityField => max(0, $value),
            $unlimitedField => 'OFF',
        ];
    }

    private function toMegabytes(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['unlimited', '-1'], true)) {
            return null;
        }

        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }

        preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $raw, $matches);
        $number = (float) ($matches[1] ?? 0);
        $unit = strtoupper($matches[2] ?? 'M');

        return match ($unit) {
            'K' => round($number / 1024, 2),
            'M' => round($number, 2),
            'G' => round($number * 1024, 2),
            default => round($number, 2),
        };
    }
}
