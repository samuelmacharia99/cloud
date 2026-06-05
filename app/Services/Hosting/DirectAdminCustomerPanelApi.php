<?php

namespace App\Services\Hosting;

use App\Models\Node;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Support\Str;

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

        $keyName = 'talksasa-'.Str::lower(Str::random(20));
        $response = $this->directAdmin->executeAdminApiCall('CMD_API_LOGIN_KEYS', [
            'action' => 'create',
            'user' => $username,
            'type' => 'one_time',
            'login_key_name' => $keyName,
            'expiry' => 2,
        ], 'POST');

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message']];
        }

        $key = $response['data']['key']
            ?? $response['data']['login_key']
            ?? $response['data']['login_key_name']
            ?? null;

        if (! is_string($key) || $key === '') {
            return ['success' => false, 'message' => 'DirectAdmin did not return a one-time login key.'];
        }

        $url = rtrim($panelUrl, '/').'/api/login/url?username='.rawurlencode($username).'&key='.rawurlencode($key);

        return ['success' => true, 'url' => $url];
    }

    /**
     * @return array{success: bool, data: array<string, mixed>, message: string}
     */
    public function getDashboard(string $username, ?string $domain = null): array
    {
        $config = $this->directAdmin->executeUserApiCall($username, 'CMD_API_SHOW_USER_CONFIG', [
            'user' => $username,
        ]);

        if (! $config['success']) {
            return $config;
        }

        $stats = $this->directAdmin->executeUserApiCall($username, 'CMD_API_USER_STATS', [
            'user' => $username,
        ]);

        $data = $config['data'];
        $usage = $stats['success'] ? $stats['data'] : [];

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeDashboard($username, $data, $usage, $domain),
        ];
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listDnsRecords(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_DNS_CONTROL', [
            'domain' => $domain,
            'action' => 'view',
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
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
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_DATABASES', [
            'action' => 'list',
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $this->normalizeList($response['data'], 'list', fn ($item) => [
                'name' => (string) $item,
            ]),
        ];
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
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_SUBDOMAINS', [
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
            'request' => 'letsencrypt',
            'le_select0' => $domain,
            'wildcard' => 'no',
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listCronJobs(string $username, string $domain): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON', [
            'domain' => $domain,
            'action' => 'view',
        ]);

        if (! $response['success']) {
            return ['success' => false, 'message' => $response['message'], 'data' => []];
        }

        $jobs = [];
        foreach ($response['data'] as $key => $value) {
            if (! preg_match('/^cron\d*$/', (string) $key)) {
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
    public function createCronJob(string $username, string $domain, string $minute, string $hour, string $day, string $month, string $weekday, string $command): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON', [
            'action' => 'create',
            'domain' => $domain,
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
    public function deleteCronJob(string $username, string $domain, string $cronId): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_CRON', [
            'action' => 'delete',
            'domain' => $domain,
            'select0' => $cronId,
        ], 'POST');
    }

    /**
     * @return array{success: bool, data: array<int, array<string, mixed>>, message: string}
     */
    public function listBackups(string $username): array
    {
        $response = $this->directAdmin->executeUserApiCall($username, 'CMD_API_BACKUP', [
            'action' => 'view',
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
    public function createBackup(string $username): array
    {
        return $this->directAdmin->executeUserApiCall($username, 'CMD_API_BACKUP', [
            'action' => 'create',
            'select0' => 'domain',
            'select1' => 'email',
            'select2' => 'ftp',
            'select3' => 'database',
        ], 'POST');
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
     * @return array<string, mixed>
     */
    private function normalizeDashboard(string $username, array $config, array $usage, ?string $domain): array
    {
        $diskQuota = $config['quota'] ?? $config['disk'] ?? $usage['quota'] ?? null;
        $diskUsed = $usage['quota_used'] ?? $config['quota_used'] ?? $usage['disk'] ?? null;
        $bwQuota = $config['bandwidth'] ?? $usage['bandwidth'] ?? null;
        $bwUsed = $usage['bandwidth_used'] ?? $config['bandwidth_used'] ?? null;

        return [
            'username' => $username,
            'domain' => $domain ?? ($config['domain'] ?? null),
            'package' => $config['package'] ?? ($config['package_name'] ?? null),
            'suspended' => in_array(strtolower((string) ($config['suspended'] ?? 'no')), ['yes', '1'], true),
            'disk' => [
                'used_mb' => $this->toMegabytes($diskUsed),
                'limit_mb' => $this->toMegabytes($diskQuota),
            ],
            'bandwidth' => [
                'used_mb' => $this->toMegabytes($bwUsed),
                'limit_mb' => $this->toMegabytes($bwQuota),
            ],
            'counts' => [
                'email' => (int) ($usage['email'] ?? $config['email'] ?? 0),
                'email_limit' => (int) ($config['email_limit'] ?? $config['email'] ?? 0),
                'ftp' => (int) ($usage['ftp'] ?? $config['ftp'] ?? 0),
                'ftp_limit' => (int) ($config['ftp_limit'] ?? $config['ftp'] ?? 0),
                'database' => (int) ($usage['mysql'] ?? $config['mysql'] ?? 0),
                'database_limit' => (int) ($config['mysql_limit'] ?? $config['mysql'] ?? 0),
                'subdomain' => (int) ($usage['subdomains'] ?? $config['subdomains'] ?? 0),
            ],
            'nameservers' => array_values(array_filter([
                $config['ns1'] ?? null,
                $config['ns2'] ?? null,
                $config['ns3'] ?? null,
                $config['ns4'] ?? null,
            ])),
            'panel_url' => $this->node->getDirectAdminPanelUrl(),
            'webmail_url' => $domain ? 'https://'.ltrim($domain, '.').'/webmail' : null,
        ];
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
