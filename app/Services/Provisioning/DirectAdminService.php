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

            if (filled($ownerResellerUsername)) {
                $payload['reseller'] = $ownerResellerUsername;
            }

            $response = $this->httpClient()
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
     * Parse DirectAdmin package data into standardized format
     */
    private function parsePackageData(string $packageName, array $data): array
    {
        return [
            'name' => $packageName,
            'package_key' => Str::slug($packageName),
            'description' => $data['description'] ?? null,
            'disk_quota' => $this->convertToGb($data['disk'] ?? '0'),
            'bandwidth_quota' => $this->convertToGb($data['bandwidth'] ?? '0'),
            'num_domains' => (int) ($data['domainptr'] ?? 1),
            'num_ftp' => (int) ($data['ftp'] ?? 1),
            'num_email_accounts' => (int) ($data['email'] ?? 0),
            'num_databases' => (int) ($data['mysql'] ?? 0),
            'num_subdomains' => $this->parseSubdomains($data['subdomains'] ?? '0'),
            'features' => $this->extractFeatures($data),
        ];
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
        $result = ['synced' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

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
            foreach ($packages as $packageData) {
                try {
                    // Query by BOTH node_id AND package_key to avoid overwriting packages from other nodes
                    $existing = DirectAdminPackage::where('node_id', $this->node?->id)
                        ->where('package_key', $packageData['package_key'])
                        ->first();

                    if ($existing) {
                        // Update existing package for this node
                        $updated = $existing->update(array_merge($packageData, ['node_id' => $this->node?->id]));
                        if ($updated) {
                            $result['updated']++;
                            Log::debug('Updated DirectAdmin package', [
                                'node_id' => $this->node?->id,
                                'package_key' => $packageData['package_key'],
                                'package_name' => $packageData['name'],
                            ]);
                        } else {
                            $result['failed']++;
                            $result['errors'][] = "Failed to update {$packageData['package_key']}: Update returned false";
                        }
                    } else {
                        // Create new package for this node
                        $created = DirectAdminPackage::create(array_merge(
                            $packageData,
                            [
                                'is_active' => true,
                                'node_id' => $this->node?->id,
                            ]
                        ));

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

            Log::info('DirectAdmin package sync completed', [
                'node_id' => $this->node?->id,
                'node_name' => $this->node?->name,
                'synced' => $result['synced'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ]);

            return $result;
        });
    }
}
