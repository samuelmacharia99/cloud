<?php

namespace App\Services\Registrar\Openprovider;

use App\Models\Registrar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenproviderClient
{
    private const TOKEN_TTL_SECONDS = 3500;

    public function __construct(private Registrar $registrar) {}

    public static function forRegistrar(Registrar $registrar): self
    {
        return new self($registrar);
    }

    public function baseUrl(): string
    {
        $config = $this->registrar->config ?? [];
        $configured = trim((string) ($config['api_base_url'] ?? ''));

        if ($configured !== '') {
            return rtrim($configured, '/').'/';
        }

        return $this->registrar->environment === 'sandbox'
            ? 'https://api.cte.openprovider.eu/v1beta/'
            : 'https://api.openprovider.eu/v1beta/';
    }

    /**
     * @return array{token: string, reseller_id: int}
     */
    public function login(bool $forceRefresh = false): array
    {
        $cacheKey = 'openprovider.token.'.$this->registrar->id;

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! empty($cached['token'])) {
                return $cached;
            }
        }

        $config = $this->registrar->config ?? [];
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new OpenproviderException('Openprovider username and password are required.');
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->post($this->baseUrl().'auth/login', [
                'username' => $username,
                'password' => $password,
                'ip' => trim((string) ($config['login_ip'] ?? '0.0.0.0')) ?: '0.0.0.0',
            ]);

        $payload = $this->decode($response);

        $token = (string) ($payload['data']['token'] ?? '');
        if ($token === '') {
            throw new OpenproviderException('Openprovider login succeeded but no token was returned.');
        }

        $session = [
            'token' => $token,
            'reseller_id' => (int) ($payload['data']['reseller_id'] ?? 0),
        ];

        Cache::put($cacheKey, $session, self::TOKEN_TTL_SECONDS);

        return $session;
    }

    public function forgetToken(): void
    {
        Cache::forget('openprovider.token.'.$this->registrar->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, data: $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function put(string $path, array $data = []): array
    {
        return $this->request('PUT', $path, data: $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, query: $query);
    }

    /**
     * @param  list<array{name: string, extension: string}>  $domains
     * @return list<array<string, mixed>>
     */
    public function checkDomains(array $domains, bool $withPrice = false): array
    {
        $payload = $this->post('domains/check', [
            'domains' => array_map(fn (array $d) => [
                'name' => $d['name'],
                'extension' => self::normalizeExtension($d['extension']),
            ], $domains),
            'with_price' => $withPrice,
        ]);

        return $payload['data']['results'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function registerDomain(array $body): array
    {
        return $this->post('domains', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function transferDomain(array $body): array
    {
        return $this->post('domains/transfer', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function renewDomain(int $domainId, int $period = 1): array
    {
        return $this->post("domains/{$domainId}/renew", ['period' => $period]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDomain(int $domainId): array
    {
        return $this->get("domains/{$domainId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function searchDomain(string $name, string $extension): array
    {
        return $this->get('domains', [
            'domain_name_pattern' => $name,
            'extension' => self::normalizeExtension($extension),
            'limit' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getReseller(): array
    {
        return $this->get('resellers', ['with_statistics' => 'true']);
    }

    public static function normalizeExtension(string $extension): string
    {
        return ltrim(strtolower(trim($extension)), '.');
    }

    /**
     * @param  array{ns1?: string, ns2?: ?string, ns3?: ?string, ns4?: ?string}|list<mixed>  $nameservers
     * @return list<array{name: string}>
     */
    public static function nameServerRecords(array $nameservers): array
    {
        $values = [];

        if (isset($nameservers['ns1']) || isset($nameservers['ns2']) || isset($nameservers['ns3']) || isset($nameservers['ns4'])) {
            foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $key) {
                $name = trim((string) ($nameservers[$key] ?? ''));
                if ($name !== '') {
                    $values[] = $name;
                }
            }
        } else {
            foreach ($nameservers as $ns) {
                $name = is_array($ns) ? trim((string) ($ns['name'] ?? $ns['ns1'] ?? '')) : trim((string) $ns);
                if ($name !== '') {
                    $values[] = $name;
                }
            }
        }

        $records = [];
        $seen = [];

        foreach ($values as $name) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $records[] = ['name' => $name];
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $session = $this->login($attempt > 1);
            $client = $this->authenticatedClient($session['token']);

            $response = match (strtoupper($method)) {
                'GET' => $client->get(ltrim($path, '/'), $query),
                'POST' => $client->post(ltrim($path, '/'), $data),
                'PUT' => $client->put(ltrim($path, '/'), $data),
                'DELETE' => $client->delete(ltrim($path, '/'), $query),
                default => throw new OpenproviderException("Unsupported HTTP method [{$method}]."),
            };

            if ($response->status() === 401 && $attempt === 1) {
                $this->forgetToken();

                continue;
            }

            return $this->decode($response);
        }
    }

    private function authenticatedClient(string $token): PendingRequest
    {
        return Http::timeout(45)
            ->acceptJson()
            ->withToken($token)
            ->baseUrl($this->baseUrl());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if (! $response->successful() && $response->json('code') === null) {
            $body = $response->body();
            $snippet = strlen($body) > 300 ? substr($body, 0, 300).'…' : $body;

            throw new OpenproviderException(
                "Openprovider HTTP {$response->status()}: ".($snippet ?: 'Empty response'),
                $response->status(),
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new OpenproviderException('Openprovider returned an invalid JSON response.');
        }

        $code = (int) ($payload['code'] ?? -1);
        if ($code !== 0) {
            throw new OpenproviderException(
                self::formatApiError($code, (string) ($payload['desc'] ?? ''), $payload['data'] ?? null),
                $code,
                $payload,
            );
        }

        return $payload;
    }

    /**
     * Build a user-facing error message from an Openprovider API error payload.
     */
    public static function formatApiError(int $code, string $desc, mixed $data = null): string
    {
        $desc = trim($desc);
        $detail = self::extractDataDetail($data);

        if ($detail !== null) {
            if ($desc === '' || self::isGenericErrorDescription($desc, $code)) {
                return $detail;
            }

            if (! str_contains(strtolower($desc), strtolower($detail))) {
                return "{$desc} Registry: {$detail}";
            }
        }

        if ($desc !== '') {
            return $desc;
        }

        return "Openprovider API error (code {$code})";
    }

    /**
     * Extract failure reason from a successful API payload with status FAI.
     *
     * @param  array<string, mixed>  $data
     */
    public static function formatOperationFailure(array $data, string $fallback = 'Registrar rejected the request.'): string
    {
        $detail = self::extractDataDetail($data);

        if ($detail !== null) {
            return $detail;
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        return $reason !== '' ? $reason : $fallback;
    }

    public static function isGenericErrorDescription(string $desc, int $code): bool
    {
        if ($code === 399) {
            return true;
        }

        $normalized = strtolower($desc);

        return str_contains($normalized, 'an error has occurred')
            || str_contains($normalized, 'refer to the registry message')
            || str_contains($normalized, 'please check the error description');
    }

    /**
     * @return non-empty-string|null
     */
    public static function extractDataDetail(mixed $data): ?string
    {
        if (! is_array($data)) {
            return is_string($data) && trim($data) !== '' ? trim($data) : null;
        }

        foreach ([
            'registry_message',
            'registryMessage',
            'reason',
            'error',
            'description',
            'desc',
            'message',
            'msg',
        ] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $nested = self::extractDataDetail($row);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }

            $nested = self::extractDataDetail($value);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
