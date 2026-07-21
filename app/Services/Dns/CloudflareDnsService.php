<?php

namespace App\Services\Dns;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareDnsService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function isEnabled(): bool
    {
        return in_array(Setting::getValue('cloudflare_enabled', 'false'), ['1', 'true', true], true);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled($this->apiToken())
            && filled($this->accountId());
    }

    public function apiToken(): ?string
    {
        $token = Setting::getValue('cloudflare_api_token');

        if (! filled($token)) {
            return null;
        }

        $token = trim((string) $token);
        $token = preg_replace('/\s+/', '', $token) ?? $token;

        if (str_starts_with(strtolower($token), 'bearer')) {
            $token = trim(substr($token, 6));
        }

        // Cloudflare rejects malformed Authorization headers (6003) for truncated/garbage tokens.
        if ($token === '' || ! preg_match('/^[A-Za-z0-9\-_]+$/', $token)) {
            return null;
        }

        return $token;
    }

    public function accountId(): ?string
    {
        $id = Setting::getValue('cloudflare_account_id');

        return filled($id) ? (string) $id : null;
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function brandedNameservers(): array
    {
        return [
            'ns1' => $this->sanitizeNameserver(Setting::getValue('cloudflare_branded_ns1', '')) ?? '',
            'ns2' => $this->sanitizeNameserver(Setting::getValue('cloudflare_branded_ns2')),
            'ns3' => $this->sanitizeNameserver(Setting::getValue('cloudflare_branded_ns3')),
            'ns4' => $this->sanitizeNameserver(Setting::getValue('cloudflare_branded_ns4')),
        ];
    }

    private function sanitizeNameserver(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if ($value === '' || $value === '0' || $value === '-' || $value === 'ns.example.com') {
            return null;
        }

        return $value;
    }

    /**
     * @return array{success: bool, message: string, account_name?: string}
     */
    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'message' => 'Cloudflare DNS is disabled in settings.'];
        }

        if (! filled($this->apiToken())) {
            $raw = Setting::getValue('cloudflare_api_token');
            if (filled($raw)) {
                return [
                    'success' => false,
                    'message' => 'Cloudflare API token looks malformed (often causes “Invalid request headers”). Paste the full token only — no “Bearer”, no spaces/newlines — then Save and test again.',
                ];
            }

            return ['success' => false, 'message' => 'Cloudflare API token is not configured.'];
        }

        if (! filled($this->accountId())) {
            return ['success' => false, 'message' => 'Cloudflare account ID is not configured.'];
        }

        $response = $this->request('GET', '/accounts/'.$this->accountId());

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => $response['message'] ?? 'Cloudflare API connection failed.',
            ];
        }

        $name = $response['data']['name'] ?? 'Cloudflare account';

        return [
            'success' => true,
            'message' => "Connected to {$name}.",
            'account_name' => $name,
        ];
    }

    /**
     * @return array{success: bool, message: string, zone_id?: string, nameservers?: list<string>}
     */
    public function createZone(string $fqdn): array
    {
        $response = $this->request('POST', '/zones', [
            'name' => strtolower($fqdn),
            'account' => ['id' => $this->accountId()],
            'type' => 'full',
        ]);

        if (! $response['success']) {
            return $response;
        }

        $zone = $response['data'] ?? [];

        return [
            'success' => true,
            'message' => 'Zone created.',
            'zone_id' => (string) ($zone['id'] ?? ''),
            'nameservers' => array_values(array_filter($zone['name_servers'] ?? [])),
        ];
    }

    /**
     * @return array{success: bool, message: string, zone_id?: string, nameservers?: list<string>}
     */
    public function findZoneByName(string $fqdn): array
    {
        $response = $this->request('GET', '/zones', [
            'name' => strtolower($fqdn),
            'account.id' => $this->accountId(),
        ]);

        if (! $response['success']) {
            return $response;
        }

        $zones = $response['data'] ?? [];
        $zone = $zones[0] ?? null;

        if (! $zone) {
            return ['success' => false, 'message' => 'Zone not found on Cloudflare.'];
        }

        return [
            'success' => true,
            'message' => 'Zone found.',
            'zone_id' => (string) ($zone['id'] ?? ''),
            'nameservers' => array_values(array_filter($zone['name_servers'] ?? [])),
        ];
    }

    /**
     * @return array{success: bool, message: string, records?: list<array<string, mixed>>}
     */
    public function listRecords(string $zoneId): array
    {
        $response = $this->request('GET', '/zones/'.$zoneId.'/dns_records', ['per_page' => 100]);

        if (! $response['success']) {
            return $response;
        }

        $records = collect($response['data'] ?? [])
            ->map(fn (array $record) => $this->normalizeRecord($record))
            ->values()
            ->all();

        return [
            'success' => true,
            'message' => 'OK',
            'records' => $records,
        ];
    }

    /**
     * @return array{success: bool, message: string, record?: array<string, mixed>}
     */
    public function createRecord(
        string $zoneId,
        string $type,
        string $name,
        string $content,
        int $ttl = 3600,
        ?int $priority = null,
        ?bool $proxied = null,
    ): array {
        $type = strtoupper($type);
        $payload = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => max(1, $ttl),
        ];

        if ($priority !== null && in_array($type, ['MX', 'SRV'], true)) {
            $payload['priority'] = $priority;
        }

        if ($this->supportsProxy($type) && $proxied !== null) {
            $payload['proxied'] = $proxied;
            if ($proxied) {
                $payload['ttl'] = 1; // Cloudflare Auto when proxied
            }
        }

        $response = $this->request('POST', '/zones/'.$zoneId.'/dns_records', $payload);

        if (! $response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'message' => 'Record created.',
            'record' => $this->normalizeRecord($response['data'] ?? []),
        ];
    }

    /**
     * @return array{success: bool, message: string, record?: array<string, mixed>}
     */
    public function updateRecord(
        string $zoneId,
        string $recordId,
        string $type,
        string $name,
        string $content,
        int $ttl = 3600,
        ?int $priority = null,
        ?bool $proxied = null,
    ): array {
        $type = strtoupper($type);
        $payload = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => max(1, $ttl),
        ];

        if ($priority !== null && in_array($type, ['MX', 'SRV'], true)) {
            $payload['priority'] = $priority;
        }

        if ($this->supportsProxy($type) && $proxied !== null) {
            $payload['proxied'] = $proxied;
            if ($proxied) {
                $payload['ttl'] = 1;
            }
        }

        $response = $this->request('PATCH', '/zones/'.$zoneId.'/dns_records/'.$recordId, $payload);

        if (! $response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'message' => 'Record updated.',
            'record' => $this->normalizeRecord($response['data'] ?? []),
        ];
    }

    public function supportsProxy(string $type): bool
    {
        return in_array(strtoupper($type), ['A', 'AAAA', 'CNAME'], true);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteRecord(string $zoneId, string $recordId): array
    {
        return $this->request('DELETE', '/zones/'.$zoneId.'/dns_records/'.$recordId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{success: bool, message: string, data?: mixed}
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $token = $this->apiToken();

        if (! $token) {
            return ['success' => false, 'message' => 'Cloudflare API token is not configured.'];
        }

        $attempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = Http::withToken($token)
                    ->acceptJson()
                    ->connectTimeout(15)
                    ->timeout(60);

                $url = self::API_BASE.$path;

                $response = match (strtoupper($method)) {
                    'GET' => $client->get($url, $body),
                    'POST' => $client->post($url, $body),
                    'PATCH' => $client->patch($url, $body),
                    'DELETE' => $client->delete($url),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
                };

                $json = $response->json();

                if (! is_array($json)) {
                    return ['success' => false, 'message' => 'Invalid response from Cloudflare API.'];
                }

                if (! ($json['success'] ?? false)) {
                    $errors = collect($json['errors'] ?? [])
                        ->pluck('message')
                        ->filter()
                        ->implode(' ');

                    Log::warning('Cloudflare API error', [
                        'path' => $path,
                        'status' => $response->status(),
                        'errors' => $json['errors'] ?? [],
                    ]);

                    return [
                        'success' => false,
                        'message' => $errors !== '' ? $errors : 'Cloudflare API request failed.',
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'OK',
                    'data' => $json['result'] ?? null,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $retryable = $this->isRetryableTransportError($lastError);

                Log::error('Cloudflare API exception', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'retryable' => $retryable,
                    'error' => $lastError,
                ]);

                if (! $retryable || $attempt === $attempts) {
                    break;
                }

                usleep(250000 * $attempt);
            }
        }

        return ['success' => false, 'message' => $lastError ?? 'Cloudflare API request failed.'];
    }

    private function isRetryableTransportError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'curl error 28')
            || str_contains($lower, 'curl error 7')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'ssl connection timeout');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'content' => (string) ($record['content'] ?? ''),
            'ttl' => (int) ($record['ttl'] ?? 3600),
            'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
            'proxied' => (bool) ($record['proxied'] ?? false),
        ];
    }
}
