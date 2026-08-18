<?php

namespace App\Services\Registrar\Cosmotown;

use App\Models\Registrar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CosmotownClient
{
    public const SANDBOX_BASE = 'https://sandbox.cosmotown.com/v1/';

    public const PRODUCTION_BASE = 'https://www.cosmotown.com/v1/';

    public function __construct(private Registrar $registrar) {}

    public static function forRegistrar(Registrar $registrar): self
    {
        return new self($registrar);
    }

    public function baseUrl(): string
    {
        $config = $this->registrar->config ?? [];
        $configured = trim((string) ($config['api_base_url'] ?? ''));

        $url = $configured !== ''
            ? $configured
            : ($this->registrar->environment === 'sandbox'
                ? self::SANDBOX_BASE
                : self::PRODUCTION_BASE);

        return $this->normalizeBaseUrl($url);
    }

    /**
     * @return array{ip: string}
     */
    public function ping(): array
    {
        $payload = $this->get('reseller/ping');
        $ip = trim((string) (
            $payload['ip']
            ?? $payload['IP']
            ?? $payload['client_ip']
            ?? $payload['remote_ip']
            ?? ''
        ));

        return ['ip' => $ip];
    }

    /**
     * Confirms the API token is accepted using the documented domainepp endpoint.
     * An empty auth_code or a 4xx domain error still means Cosmotown authenticated the request.
     *
     * @return array<string, mixed>
     */
    public function probeAuthentication(): array
    {
        try {
            return $this->get('reseller/domainepp', ['domain' => 'connection-test.invalid']);
        } catch (CosmotownException $e) {
            if (in_array($e->httpStatus, [401, 403], true)) {
                throw $e;
            }

            if ($e->httpStatus >= 400 && $e->httpStatus < 500) {
                return $e->response ?? ['authenticated' => true];
            }

            throw $e;
        }
    }

    /**
     * @return array{domains: list<array<string, mixed>>}
     */
    public function listDomains(int $limit = 100, int $offset = 0): array
    {
        $payload = $this->get('reseller/listdomains', [
            'limit' => max(1, $limit),
            'offset' => max(0, $offset),
        ]);

        $domains = $payload['domains'] ?? null;
        if (! is_array($domains) && array_is_list($payload)) {
            $domains = $payload;
        }
        if (! is_array($domains)) {
            $domains = [];
        }

        return ['domains' => array_values(array_filter($domains, 'is_array'))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllDomains(int $pageSize = 100): array
    {
        $all = [];
        $offset = 0;
        $pageSize = max(1, min(100, $pageSize));

        do {
            $page = $this->listDomains($pageSize, $offset);
            $all = array_merge($all, $page['domains']);
            $offset += $pageSize;
        } while (count($page['domains']) === $pageSize);

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDomainInfo(string $domain): array
    {
        return $this->get('reseller/domaininfo', ['domain' => $this->normalizeDomain($domain)]);
    }

    /**
     * @return array{auth_code: string}
     */
    public function getDomainAuthCode(string $domain): array
    {
        $payload = $this->get('reseller/domainepp', ['domain' => $this->normalizeDomain($domain)]);
        $authCode = trim((string) ($payload['auth_code'] ?? ''));

        if ($authCode === '') {
            throw new CosmotownException('Cosmotown returned no auth_code for this domain.', 200, $payload);
        }

        return ['auth_code' => $authCode];
    }

    /**
     * @param  list<array{name: string, years: int}>  $items
     * @return list<array<string, mixed>>
     */
    public function registerDomains(array $items, string $couponId = ''): array
    {
        $payload = [
            'items' => $items,
        ];

        if ($couponId !== '') {
            $payload['coupon_id'] = $couponId;
        }

        return $this->listPayload($this->post('reseller/registerdomains', $payload));
    }

    /**
     * @param  list<array{name: string, years: int}>  $items
     * @return array<string, mixed>
     */
    public function renewDomains(array $items): array
    {
        return $this->post('reseller/renewdomains', ['items' => $items]);
    }

    /**
     * @param  list<array{name: string, authCode: string}>  $items
     * @return list<array<string, mixed>>
     */
    public function transferDomains(array $items): array
    {
        return $this->listPayload($this->post('reseller/transferdomains', ['items' => $items]));
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, mixed>
     */
    public function saveDomainNameservers(string $domain, array $nameservers): array
    {
        return $this->post('reseller/savedomainnameservers', [
            'domain' => $this->normalizeDomain($domain),
            'nameservers' => array_values($nameservers),
        ]);
    }

    /**
     * @param  list<string>  $domains
     * @return list<array<string, mixed>>
     */
    public function getDomainStatus(array $domains): array
    {
        return $this->listPayload($this->post('reseller/domainstatus', [
            'domains' => array_values($domains),
        ]));
    }

    /**
     * @param  array{registrant: array<string, string>, administrative: array<string, string>, technical: array<string, string>, billing: array<string, string>}  $contacts
     * @return array<string, mixed>
     */
    public function saveContactInfo(array $contacts, ?string $domain = null): array
    {
        $query = [];
        if ($domain !== null && trim($domain) !== '') {
            $query['domain'] = $this->normalizeDomain($domain);
        }

        return $this->post('reseller/contactinfo', $contacts, $query);
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
    public function post(string $path, array $data = [], array $query = []): array
    {
        return $this->request('POST', $path, query: $query, data: $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array $data = []): array
    {
        $url = $this->baseUrl().ltrim($path, '/');
        $pending = $this->http();

        $response = match (strtoupper($method)) {
            'GET' => $pending->get($url, $query),
            'POST' => $query === []
                ? $pending->post($url, $data)
                : $pending->withQueryParameters($query)->post($url, $data),
            default => throw new CosmotownException('Unsupported HTTP method: '.$method),
        };

        return $this->decode($response);
    }

    private function http(): PendingRequest
    {
        $token = trim((string) (($this->registrar->config ?? [])['api_token'] ?? ''));
        if ($token === '') {
            throw new CosmotownException('Cosmotown API token is required.');
        }

        return Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-API-TOKEN' => $token,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->body();
        $payload = $response->json();

        if (! is_array($payload)) {
            if ($this->looksLikeHtml($body, $response->header('Content-Type'))) {
                throw new CosmotownException(
                    'Cosmotown returned a web page instead of JSON. Production Reseller API is https://www.cosmotown.com/v1/ (not the apex marketing site).',
                    $response->status(),
                    ['raw' => substr($body, 0, 240)]
                );
            }

            $payload = ['raw' => $body];
        }

        $status = $response->status();

        if ($status === 403) {
            throw new CosmotownException(
                $this->errorMessage($payload, 'Unauthorized. API key is invalid or IP is not whitelisted.'),
                403,
                $payload
            );
        }

        if ($status >= 500) {
            throw new CosmotownException(
                $this->errorMessage($payload, 'Cosmotown server error (HTTP '.$status.').'),
                $status,
                $payload
            );
        }

        if ($status >= 400) {
            throw new CosmotownException(
                $this->errorMessage($payload, 'Cosmotown request failed (HTTP '.$status.').'),
                $status,
                $payload
            );
        }

        if (! $response->successful()) {
            throw new CosmotownException(
                $this->errorMessage($payload, 'Unexpected Cosmotown response (HTTP '.$status.').'),
                $status,
                $payload
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function listPayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter(
                $payload,
                fn ($row) => is_array($row)
            ));
        }

        foreach (['items', 'results', 'domains', 'data'] as $key) {
            $rows = $payload[$key] ?? null;
            if (is_array($rows) && $rows !== [] && array_is_list($rows)) {
                return array_values(array_filter(
                    $rows,
                    fn ($row) => is_array($row)
                ));
            }
        }

        return [$payload];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorMessage(array $payload, string $fallback): string
    {
        foreach (['error_message', 'message', 'error', 'detail'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            throw new CosmotownException('Domain is required.');
        }

        return $domain;
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';

        if ($host === 'cosmotown.com') {
            $host = 'www.cosmotown.com';
        }

        if (in_array($host, ['www.cosmotown.com', 'sandbox.cosmotown.com'], true)) {
            return "{$scheme}://{$host}/v1/";
        }

        return rtrim($url, '/').'/';
    }

    private function looksLikeHtml(string $body, mixed $contentType): bool
    {
        $type = strtolower(trim((string) $contentType));
        if (str_contains($type, 'text/html')) {
            return true;
        }

        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '<!')
            || str_starts_with(strtolower($trimmed), '<html');
    }
}
