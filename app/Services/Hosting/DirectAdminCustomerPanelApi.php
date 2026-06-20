<?php

namespace App\Services\Hosting;

use App\Models\Node;
use App\Services\Provisioning\DirectAdminService;

/**
 * End-user DirectAdmin operations executed via the platform admin API (impersonation).
 */
class DirectAdminCustomerPanelApi
{
    public function __construct(
        private DirectAdminService $directAdmin,
        private Node $node,
    ) {}

    public static function forServiceNode(Node $node): self
    {
        return new self(new DirectAdminService($node), $node);
    }

    public function isAvailable(): bool
    {
        return $this->directAdmin->isConfigured();
    }

    /**
     * @return array{success: bool, url?: string, message?: string}
     */
    public function createOneTimeLoginUrl(string $username): array
    {
        $panelUrl = $this->node->getDirectAdminPanelUrl();
        if (! $panelUrl) {
            return ['success' => false, 'message' => 'Control panel URL is not configured for this server.'];
        }

        /**
         * DirectAdmin Login Keys are typically scoped to the authenticated account.
         * To create a one-time login URL for an end user, we must call the endpoint using
         * admin "login-as" (admin|username), which is handled by executeUserApiCall().
         *
         * The legacy API returns the login URL in the "details" field.
         */
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_LOGIN_KEYS', [
            'action' => 'create',
            'type' => 'one_time_url',
            'expiry' => '5m',
            'login_keys_notify_on_creation' => 0,
        ], 'POST');

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message']];
        }

        $detailsUrl = $response['data']['details'] ?? null;
        if (! is_string($detailsUrl) || $detailsUrl === '') {
            return ['success' => false, 'message' => 'DirectAdmin did not return a one-time login URL.'];
        }

        // DirectAdmin sometimes returns a relative URL in details.
        $url = str_starts_with($detailsUrl, 'http')
            ? $detailsUrl
            : rtrim($panelUrl, '/').'/'.ltrim($detailsUrl, '/');

        return ['success' => true, 'url' => $url];
    }

    /**
     * @return array{success: bool, data: array<string, mixed>, message: string}
     */
    public function getDashboard(string $username, ?string $domain = null): array
    {
        $config = $this->directAdmin->executeAdminApiCall('CMD_API_SHOW_USER_CONFIG', [
            'user' => $username,
        ]);

        if (! $config['success']) {
            return $config;
        }

        $usage = $this->directAdmin->getAccountUsage($username);
        $data = $this->directAdmin->flattenResponseValues($config['data']);
        $databaseList = $this->listDatabases($username);
        $databaseUsed = $databaseList['success']
            ? count($databaseList['data'])
            : $this->resolveDatabaseUsedCount($username, $usage);

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeDashboard(
                $username,
                $data,
                $usage,
                $domain,
                $databaseUsed,
                $databaseList['success'] ? array_column($databaseList['data'], 'name') : [],
            ),
        ];
    }

    /**
     * Actual database count from DirectAdmin — never treat package limit (config mysql) as usage.
     */
    public function resolveDatabaseUsedCount(string $username, array $usage = []): int
    {
        $listed = $this->listDatabases($username);
        if ($listed['success']) {
            return count($listed['data']);
        }

        if (array_key_exists('mysql', $usage)) {
            return max(0, (int) $usage['mysql']);
        }

        if (array_key_exists('mysql_used', $usage)) {
            return max(0, (int) $usage['mysql_used']);
        }

        return 0;
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listDnsRecords(string $username, string $domain): array
    {
        // Use user-level endpoint. CMD_API_DNS_ADMIN is admin-only on many installs.
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_DNS_CONTROL', [
            'domain' => $domain,
            'action' => 'view',
            'full_mx_records' => 'yes',
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        if (isset($response['data']['records']) && is_array($response['data']['records'])) {
            return [
                'success' => true,
                'message' => 'OK',
                'data' => $this->normalizeDnsAdminRecords($response['data']['records'], $domain),
            ];
        }

        if (isset($response['data']['zone']) && is_string($response['data']['zone'])) {
            return [
                'success' => true,
                'message' => 'OK',
                'data' => $this->normalizeDnsZoneText($response['data']['zone'], $domain),
            ];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeDnsRecords($response['data'], $domain),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function addDnsRecord(string $username, string $domain, string $name, string $type, string $value, int $ttl = 3600): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_DNS_CONTROL', [
            'action' => 'add',
            'domain' => $domain,
            'name' => $name,
            'type' => strtoupper($type),
            'value' => $value,
            'ttl' => $ttl,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteDnsRecord(string $username, string $domain, string $name, string $type, string $value): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_DNS_CONTROL', [
            'action' => 'delete',
            'domain' => $domain,
            'name' => $name,
            'type' => strtoupper($type),
            'value' => $value,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listEmailAccounts(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_POP', [
            'action' => 'list',
            'domain' => $domain,
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeList($response['data'], 'list', fn ($item) => [
                'account' => (string) $item,
                'email' => str_contains((string) $item, '@') ? (string) $item : $item.'@'.$domain,
            ]),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createEmailAccount(string $username, string $domain, string $localPart, string $password, int $quotaMb = 250): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_POP', [
            'action' => 'create',
            'domain' => $domain,
            'user' => $localPart,
            'passwd' => $password,
            'passwd2' => $password,
            'quota' => $quotaMb,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteEmailAccount(string $username, string $domain, string $localPart): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_POP', [
            'action' => 'delete',
            'domain' => $domain,
            'user' => $localPart,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listDatabases(string $username): array
    {
        foreach ([[], ['action' => 'list']] as $params) {
            $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_DATABASES', $params);

            if (! $response['success']) {
                continue;
            }

            $items = $this->parseDatabaseNames($response['data']);
            if ($items !== []) {
                return [
                    'success' => true,
                    'message' => 'OK',
                    'data' => array_map(fn (string $name) => ['name' => $name], $items),
                ];
            }
        }

        return ['success' => false, 'message' => 'No databases returned from DirectAdmin.', 'data' => []];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function parseDatabaseNames(array $data): array
    {
        if (isset($data['list']) && is_array($data['list'])) {
            return array_values(array_filter(array_map('strval', $data['list'])));
        }

        $normalized = $this->normalizeList($data, 'list', fn ($item) => (string) $item);

        return array_values(array_filter(array_map(
            fn (array $row) => (string) ($row['name'] ?? ''),
            $normalized,
        )));
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function createDatabase(string $username, string $name, string $password): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_DATABASES', [
            'action' => 'create',
            'name' => $name,
            'user' => $name,
            'passwd' => $password,
            'passwd2' => $password,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteDatabase(string $username, string $name): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_DATABASES', [
            'action' => 'delete',
            'name' => $name,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listSubdomains(string $username, string $domain): array
    {
        // Listing does not require an action (passing one may break on some DA builds).
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_SUBDOMAINS', [
            'domain' => $domain,
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeList($response['data'], 'list', fn ($item) => [
                'subdomain' => (string) $item,
                'fqdn' => str_ends_with((string) $item, '.'.$domain) ? (string) $item : $item.'.'.$domain,
            ]),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createSubdomain(string $username, string $domain, string $subdomain): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_SUBDOMAINS', [
            'action' => 'create',
            'domain' => $domain,
            'subdomain' => $subdomain,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteSubdomain(string $username, string $domain, string $subdomain): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_SUBDOMAINS', [
            'action' => 'delete',
            'domain' => $domain,
            'select0' => $subdomain,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listFtpAccounts(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_FTP', [
            'action' => 'list',
            'domain' => $domain,
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeList($response['data'], 'list', fn ($item) => [
                'account' => (string) $item,
            ]),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createFtpAccount(string $username, string $domain, string $ftpUser, string $password, string $path = '/'): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_FTP', [
            'action' => 'create',
            'domain' => $domain,
            'user' => $ftpUser,
            'passwd' => $password,
            'passwd2' => $password,
            'custom_val' => $path,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteFtpAccount(string $username, string $domain, string $ftpUser): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_FTP', [
            'action' => 'delete',
            'domain' => $domain,
            'select0' => $ftpUser,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<string, mixed>, message: string}
     */
    public function getSslInfo(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_SSL', [
            'domain' => $domain,
            'action' => 'view',
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => [
                'domain' => $domain,
                'ssl_on' => in_array(strtolower((string) ($response['data']['ssl'] ?? $response['data']['ssl_on'] ?? 'no')), ['yes', '1', 'on', 'true'], true),
                'letsencrypt' => in_array(strtolower((string) ($response['data']['letsencrypt'] ?? 'no')), ['yes', '1', 'on', 'true'], true),
                'raw' => $response['data'],
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function requestLetsEncrypt(string $username, string $domain): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_SSL', [
            'action' => 'save',
            'domain' => $domain,
            'type' => 'create',
            'request' => 'letsencrypt',
            'name' => $domain,
            'keysize' => 'secp384r1',
            'encryption' => 'sha256',
            'le_select0' => $domain,
            'le_select1' => 'www.'.$domain,
            'submit' => 'save',
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listCronJobs(string $username): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON_JOBS');

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        $jobs = [];
        foreach ($response['data'] as $key => $value) {
            if (! preg_match('/^cronid\d*$/', (string) $key)) {
                continue;
            }

            $raw = is_string($value) ? trim($value) : json_encode($value);
            $schedule = null;
            $command = $raw;

            if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/s', $raw, $matches)) {
                $schedule = $matches[1];
                $command = $matches[2];
            }

            $jobs[] = [
                'id' => (string) $key,
                'schedule' => $schedule,
                'command' => $command,
            ];
        }

        return ['success' => true, 'message' => 'OK', 'data' => $jobs];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createCronJob(string $username, string $minute, string $hour, string $day, string $month, string $weekday, string $command): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON_JOBS', [
            'action' => 'create',
            'minute' => $minute,
            'hour' => $hour,
            'dayofmonth' => $day,
            'month' => $month,
            'dayofweek' => $weekday,
            'command' => $command,
        ], 'POST');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteCronJob(string $username, string $cronId): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON_JOBS', [
            'action' => 'delete',
            'select0' => $cronId,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listBackups(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_SITE_BACKUP', [
            'domain' => $domain,
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeList($response['data'], 'list', fn ($item) => [
                'filename' => (string) $item,
            ]),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function createBackup(string $username, string $domain): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_SITE_BACKUP', [
            'action' => 'backup',
            'domain' => $domain,
            'select0' => 'domain',
            'select1' => 'email',
            'select2' => 'ftp',
            'select3' => 'database',
        ], 'POST');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDnsAdminRecords(array $records, string $domain): array
    {
        $normalized = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $name = (string) ($record['name'] ?? '');
            $value = (string) ($record['value'] ?? '');
            $ttl = (int) ($record['ttl'] ?? 3600);

            $fqdn = rtrim($name, '.');
            if ($fqdn === '' || $fqdn === '@') {
                $fqdn = $domain;
            }

            $normalized[] = [
                'name' => $name === '' ? '@' : rtrim($name, '.'),
                'type' => $type,
                'value' => $value,
                'ttl' => $ttl,
                'fqdn' => $fqdn,
            ];
        }

        return $normalized;
    }

    /**
     * Minimal zone parser fallback for DA responses that only include zone text.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDnsZoneText(string $zone, string $domain): array
    {
        $records = [];

        $lines = preg_split("/\r\n|\n|\r/", $zone) ?: [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s*;.*$/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '$')) {
                continue;
            }

            // Very basic: name [ttl] [class] type value...
            $parts = preg_split('/\s+/', $line, 5) ?: [];
            if (count($parts) < 3) {
                continue;
            }

            $name = $parts[0];
            $idx = 1;

            $ttl = 3600;
            if (isset($parts[$idx]) && is_numeric($parts[$idx])) {
                $ttl = (int) $parts[$idx];
                $idx++;
            }

            if (($parts[$idx] ?? '') === 'IN') {
                $idx++;
            }

            $type = strtoupper((string) ($parts[$idx] ?? ''));
            $idx++;

            $value = trim((string) ($parts[$idx] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            $name = rtrim($name, '.');
            if ($name === $domain) {
                $name = '@';
            }

            $fqdn = $name === '@' || $name === '' ? $domain : $name.'.'.$domain;

            $records[] = [
                'name' => $name === '' ? '@' : $name,
                'type' => $type,
                'value' => $value,
                'ttl' => $ttl,
                'fqdn' => $fqdn,
            ];
        }

        return $records;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updatePassword(string $username, string $password): array
    {
        return $this->directAdmin->executeAdminApiCall('CMD_API_ACCOUNT_USER', [
            'action' => 'modify',
            'user' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        ], 'POST');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $usage
     * @param  list<string>  $databaseNames
     * @return array<string, mixed>
     */
    private function normalizeDashboard(
        string $username,
        array $config,
        array $usage,
        ?string $domain,
        ?int $databaseUsedCount = null,
        array $databaseNames = [],
    ): array {
        $diskQuota = $config['quota'] ?? $config['disk'] ?? null;
        $diskUsed = $usage['quota'] ?? $usage['quota_used'] ?? $config['quota_used'] ?? $usage['disk'] ?? $usage['disk_used'] ?? null;
        $bwQuota = $config['bandwidth'] ?? null;
        $bwUsed = $usage['bandwidth'] ?? $usage['bandwidth_used'] ?? $config['bandwidth_used'] ?? $usage['bandwidth_used_mb'] ?? null;

        $databaseUsed = $databaseUsedCount ?? (array_key_exists('mysql', $usage) ? (int) $usage['mysql'] : 0);
        $databaseLimit = $this->resolveDatabaseLimit($config);

        return [
            'username' => $username,
            'domain' => $domain ?? ($config['domain'] ?? null),
            'package' => $config['package'] ?? ($config['package_name'] ?? null),
            'suspended' => in_array(strtolower((string) ($config['suspended'] ?? 'no')), ['yes', '1'], true),
            'disk' => [
                'used_mb' => $this->toMegabytes($diskUsed) ?? 0.0,
                'limit_mb' => $this->toMegabytes($diskQuota),
            ],
            'bandwidth' => [
                'used_mb' => $this->toMegabytes($bwUsed) ?? 0.0,
                'limit_mb' => $this->toMegabytes($bwQuota),
            ],
            'counts' => [
                'email' => (int) ($usage['nemails'] ?? $usage['email'] ?? $config['nemails'] ?? $config['email'] ?? 0),
                'email_limit' => $this->resolvePackageCountLimit($config, 'nemails', 'unemails', 'email'),
                'ftp' => (int) ($usage['ftp'] ?? $config['ftp'] ?? 0),
                'ftp_limit' => $this->resolvePackageCountLimit($config, 'ftp', 'uftp'),
                'database' => $databaseUsed,
                'database_limit' => $databaseLimit,
                'subdomain' => (int) ($usage['nsubdomains'] ?? $usage['subdomains'] ?? $config['subdomains'] ?? 0),
            ],
            'databases' => array_values(array_filter($databaseNames)),
            'nameservers' => array_values(array_filter([
                $config['ns1'] ?? null,
                $config['ns2'] ?? null,
                $config['ns3'] ?? null,
                $config['ns4'] ?? null,
            ])),
            'panel_url' => $domain
                ? 'https://'.ltrim($domain, '.').':'.($this->node->da_port ?: '2222')
                : $this->node->getDirectAdminPanelUrl(),
            'webmail_url' => $domain ? 'https://'.ltrim($domain, '.').'/webmail' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveDatabaseLimit(array $config): int
    {
        if ($this->isDirectAdminUnlimited($config['umysql'] ?? null)) {
            return -1;
        }

        foreach (['mysql_limit', 'mysql', 'umysql'] as $field) {
            if (! array_key_exists($field, $config)) {
                continue;
            }

            $value = strtolower(trim((string) $config[$field]));
            if (in_array($value, ['unlimited', '-1'], true)) {
                return -1;
            }

            if ($value !== '' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolvePackageCountLimit(array $config, string $quantityField, string $unlimitedField, string ...$fallbackFields): int
    {
        if ($this->isDirectAdminUnlimited($config[$unlimitedField] ?? null)) {
            return -1;
        }

        foreach ([$quantityField, ...$fallbackFields] as $field) {
            if (! array_key_exists($field, $config)) {
                continue;
            }

            $value = strtolower(trim((string) $config[$field]));
            if (in_array($value, ['unlimited', '-1'], true)) {
                return -1;
            }

            if ($value !== '' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function isDirectAdminUnlimited(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return in_array(strtoupper(trim((string) $value)), ['ON', 'YES', 'UNLIMITED', '-1'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDnsRecords(array $data, string $domain): array
    {
        $records = [];
        $types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

        foreach ($types as $type) {
            $key = strtolower($type);
            $entries = $data[$key] ?? $data[$type] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $name => $value) {
                if (is_array($value)) {
                    foreach ($value as $subValue) {
                        $records[] = [
                            'name' => (string) $name,
                            'type' => $type,
                            'value' => (string) $subValue,
                            'ttl' => 3600,
                            'fqdn' => $name === '@' || $name === '' ? $domain : $name.'.'.$domain,
                        ];
                    }

                    continue;
                }

                $records[] = [
                    'name' => (string) $name,
                    'type' => $type,
                    'value' => (string) $value,
                    'ttl' => 3600,
                    'fqdn' => $name === '@' || $name === '' ? $domain : $name.'.'.$domain,
                ];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(string): array<string, mixed>  $mapper
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(array $data, string $listKey, callable $mapper): array
    {
        $items = $data[$listKey] ?? $data['list[]'] ?? null;

        if ($items === null) {
            $indexed = [];
            foreach ($data as $key => $value) {
                if (preg_match('/^'.preg_quote($listKey, '/').'(\d+)$/', (string) $key, $matches)) {
                    $indexed[(int) $matches[1]] = $value;
                }
            }

            $items = $indexed !== []
                ? array_values($indexed)
                : [];
        }

        if (! is_array($items)) {
            $items = [$items];
        }

        $normalized = [];
        foreach ($items as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $normalized[] = $mapper((string) $item);
        }

        return array_values($normalized);
    }

    public function normalizeSubdomainLabel(string $subdomain, string $domain): string
    {
        $label = strtolower(trim($subdomain));
        $suffix = '.'.strtolower($domain);

        if (str_ends_with($label, $suffix)) {
            $label = substr($label, 0, -strlen($suffix));
        }

        return $label;
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
