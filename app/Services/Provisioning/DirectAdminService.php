<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\Service;
use App\Models\DirectAdminPackage;
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
            $this->username = $node->ssh_username ?? Setting::getValue('directadmin_api_user', 'admin');
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
        return !empty($this->apiUrl) && !empty($this->password);
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
            'has_password' => !empty($this->password),
            'node_id' => $this->node?->id,
            'node_name' => $this->node?->name,
        ];
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

    /**
     * Fetch all packages from DirectAdmin server
     * DirectAdmin returns form-encoded list of package names: list[]=Package1&list[]=Package2
     *
     * @return array Array of packages with their specs, or empty array on failure
     */
    public function getPackages(): array
    {
        if (!$this->isConfigured()) {
            Log::warning('DirectAdmin not configured', ['node_id' => $this->node?->id]);
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->withBasicAuth($this->username, $this->password)
                ->get("{$this->apiUrl}/CMD_API_PACKAGES_USER", [
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
                if (!$responseData) {
                    Log::warning('DirectAdmin API returned invalid JSON', [
                        'node_id' => $this->node?->id,
                        'body_preview' => substr($body, 0, 200),
                    ]);
                    return [];
                }

                // Handle both formats: direct array or object with list key
                $packageNames = is_array($responseData) && isset($responseData[0]) && !isset($responseData['list'])
                    ? $responseData  // Direct array format: ["Bronze", "Gold", ...]
                    : ($responseData['list'] ?? []);  // Object format: {"list": ["Bronze", ...]}

                if (!is_array($packageNames)) {
                    $packageNames = [$packageNames];
                }

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
    private function getPackageDetails(string $packageName): ?array
    {
        try {
            $response = Http::timeout(30)
                ->withBasicAuth($this->username, $this->password)
                ->get("{$this->apiUrl}/CMD_API_PACKAGES_USER", [
                    'package' => $packageName,
                    'json' => 'yes',
                ])
                ->throw();

            if (!$response->ok()) {
                Log::warning('Failed to fetch package details - API error', [
                    'node_id' => $this->node?->id,
                    'package_name' => $packageName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $packageData = $response->json();
            if (!$packageData) {
                Log::warning('Failed to parse package details JSON', [
                    'node_id' => $this->node?->id,
                    'package_name' => $packageName,
                ]);
                return null;
            }

            return $this->parsePackageData($packageName, $packageData);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch package details', [
                'node_id' => $this->node?->id,
                'package_name' => $packageName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
            'num_domains' => (int)($data['domainptr'] ?? 1),
            'num_ftp' => (int)($data['ftp'] ?? 1),
            'num_email_accounts' => (int)($data['email'] ?? 0),
            'num_databases' => (int)($data['mysql'] ?? 0),
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

        $number = (float)($matches[1] ?? 0);
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
            default => (int)$value,
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
