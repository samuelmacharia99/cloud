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
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public static function nameServerRecords(array $nameservers): array
    {
        $records = [];

        foreach ($nameservers as $ns) {
            $name = is_array($ns) ? ($ns['name'] ?? $ns['ns1'] ?? '') : (string) $ns;
            $name = trim($name);
            if ($name !== '') {
                $records[] = ['name' => $name];
            }
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
            $message = trim((string) ($payload['desc'] ?? ''));
            if ($message === '' && isset($payload['data']['error'])) {
                $message = (string) $payload['data']['error'];
            }
            if ($message === '' && isset($payload['data']['description'])) {
                $message = (string) $payload['data']['description'];
            }

            throw new OpenproviderException(
                $message !== '' ? $message : "Openprovider API error (code {$code})",
                $code,
                $payload,
            );
        }

        return $payload;
    }
}
