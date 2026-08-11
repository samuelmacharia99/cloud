<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Mailcow REST API client (X-API-Key).
 *
 * @see https://docs.mailcow.email/third_party/api/
 */
class MailcowService
{
    public function __construct(
        private Node $node,
    ) {}

    public static function forNode(Node $node): self
    {
        return new self($node);
    }

    public function isConfigured(): bool
    {
        return $this->node->type === 'mailcow'
            && filled($this->baseUrl())
            && filled($this->apiKey());
    }

    public function baseUrl(): string
    {
        $url = trim((string) ($this->node->api_url ?: ''));
        if ($url === '' && filled($this->node->hostname)) {
            $url = 'https://'.$this->node->hostname;
        }

        return rtrim($url, '/');
    }

    public function apiKey(): string
    {
        return (string) ($this->node->api_token ?: '');
    }

    public function webmailUrl(): string
    {
        $path = (string) config('mailcow.webmail_path', '/SOGo/');
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $this->baseUrl().$path;
    }

    public function mailHostname(): string
    {
        return (string) ($this->node->hostname ?: parse_url($this->baseUrl(), PHP_URL_HOST) ?: 'mail');
    }

    /**
     * Public IPv4 of the mail node when known (for SPF ip4: mechanisms).
     */
    public function mailIpAddress(): ?string
    {
        $ip = trim((string) ($this->node->ip_address ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * @return array{success: bool, message: string, version?: string}
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Mailcow API URL and API token are required.',
            ];
        }

        $response = $this->request('GET', '/api/v1/get/status/version');

        if (! $response['success']) {
            return [
                'success' => false,
                'message' => $response['message'] ?? 'Mailcow connection failed.',
            ];
        }

        $version = is_string($response['data'] ?? null)
            ? $response['data']
            : (string) (data_get($response, 'data.version') ?? data_get($response, 'data') ?? 'ok');

        return [
            'success' => true,
            'message' => 'Connected to Mailcow.',
            'version' => $version,
        ];
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addDomain(array $attr): array
    {
        return $this->request('POST', '/api/v1/add/domain', $attr);
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function editDomain(string $domain, array $attr): array
    {
        return $this->request('POST', '/api/v1/edit/domain', [
            'items' => [$domain],
            'attr' => $attr,
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function deleteDomain(string $domain): array
    {
        return $this->request('POST', '/api/v1/delete/domain', [$domain]);
    }

    /**
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function getDomain(string $domain): array
    {
        return $this->request('GET', '/api/v1/get/domain/'.$domain);
    }

    /**
     * @return array{success: bool, message: string, data?: list<array<string, mixed>>}
     */
    public function listMailboxes(string $domain): array
    {
        $response = $this->request('GET', '/api/v1/get/mailbox/all/'.$domain);
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        // Normalise keyed object → list
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addMailbox(array $attr): array
    {
        return $this->request('POST', '/api/v1/add/mailbox', $attr);
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function editMailbox(string $email, array $attr): array
    {
        return $this->request('POST', '/api/v1/edit/mailbox', [
            'items' => [$email],
            'attr' => $attr,
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function deleteMailbox(string $email): array
    {
        return $this->request('POST', '/api/v1/delete/mailbox', [$email]);
    }

    /**
     * @return array{success: bool, message: string, data?: list<array<string, mixed>>}
     */
    public function listAliases(string $domain): array
    {
        $response = $this->request('GET', '/api/v1/get/alias/all/'.$domain);
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addAlias(array $attr): array
    {
        return $this->request('POST', '/api/v1/add/alias', $attr);
    }

    /**
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function deleteAlias(string|int $id): array
    {
        return $this->request('POST', '/api/v1/delete/alias', [(string) $id]);
    }

    /**
     * @return array{success: bool, message: string, dkim_txt?: string, selector?: string}
     */
    public function getDkim(string $domain): array
    {
        $response = $this->request('GET', '/api/v1/get/dkim/'.rawurlencode(strtolower($domain)));
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        $txt = '';
        $selector = (string) config('mailcow.dkim_selector', 'dkim');

        if (is_string($data)) {
            $txt = $data;
        } elseif (is_array($data)) {
            $txt = (string) ($data['dkim_txt'] ?? $data['pubkey'] ?? $data['txt'] ?? '');
            $selector = (string) ($data['dkim_selector'] ?? $data['selector'] ?? $selector);
            if ($txt === '' && isset($data['dkim_txt'])) {
                $txt = (string) $data['dkim_txt'];
            }
            // Some versions return { domain: { dkim_txt: ... } }
            if ($txt === '' && isset($data[$domain]) && is_array($data[$domain])) {
                $txt = (string) ($data[$domain]['dkim_txt'] ?? $data[$domain]['pubkey'] ?? '');
                $selector = (string) ($data[$domain]['dkim_selector'] ?? $selector);
            }
        }

        $txt = $this->normalizeDkimTxt($txt);

        return [
            'success' => true,
            'message' => 'OK',
            'dkim_txt' => $txt,
            'selector' => $selector !== '' ? $selector : 'dkim',
            'data' => $data,
        ];
    }

    /**
     * Generate a DKIM keypair in Mailcow for the domain.
     *
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addDkim(string $domain, ?string $selector = null, ?int $keySize = null): array
    {
        $selector = $selector ?: (string) config('mailcow.dkim_selector', 'dkim');
        $keySize = $keySize ?: (int) config('mailcow.dkim_key_size', 2048);

        // Mailcow requires the plural "domains" key (singular "domain" silently no-ops).
        return $this->request('POST', '/api/v1/add/dkim', [
            'domains' => strtolower($domain),
            'dkim_selector' => $selector,
            'key_size' => (string) $keySize,
        ]);
    }

    /**
     * Ensure Mailcow has a publishable DKIM TXT for the domain (create if missing).
     *
     * @return array{success: bool, message: string, dkim_txt: string, selector: string, created: bool}
     */
    public function ensureDkim(string $domain, ?string $selector = null, ?int $keySize = null): array
    {
        $selector = $selector ?: (string) config('mailcow.dkim_selector', 'dkim');
        $keySize = $keySize ?: (int) config('mailcow.dkim_key_size', 2048);
        $domain = strtolower($domain);

        $existing = $this->getDkim($domain);
        if (($existing['success'] ?? false) && filled($existing['dkim_txt'] ?? null)) {
            return [
                'success' => true,
                'message' => 'DKIM already present',
                'dkim_txt' => (string) $existing['dkim_txt'],
                'selector' => (string) ($existing['selector'] ?? $selector),
                'created' => false,
            ];
        }

        $add = $this->addDkim($domain, $selector, $keySize);
        $after = $this->getDkim($domain);

        if (($after['success'] ?? false) && filled($after['dkim_txt'] ?? null)) {
            return [
                'success' => true,
                'message' => ($add['success'] ?? false) ? 'DKIM created' : 'DKIM available',
                'dkim_txt' => (string) $after['dkim_txt'],
                'selector' => (string) ($after['selector'] ?? $selector),
                'created' => true,
            ];
        }

        return [
            'success' => false,
            'message' => (string) ($add['message'] ?? $after['message'] ?? 'Failed to ensure DKIM in Mailcow'),
            'dkim_txt' => '',
            'selector' => $selector,
            'created' => false,
        ];
    }

    /**
     * Normalize Mailcow DKIM payloads into a single TXT value suitable for DNS.
     */
    public function normalizeDkimTxt(string $txt): string
    {
        $txt = trim($txt);
        if ($txt === '') {
            return '';
        }

        // Cloudflare-style quoted chunks: "v=DKIM1;..." "p=..."
        if (str_contains($txt, '" "')) {
            $txt = str_replace('" "', '', $txt);
        }
        $txt = trim($txt, "\" \t\n\r");

        $lower = strtolower($txt);
        if (str_contains($lower, 'v=dkim1')) {
            return $txt;
        }

        if (str_starts_with($lower, 'p=')) {
            return 'v=DKIM1;k=rsa;t=s;s=email;'.$txt;
        }

        // Bare base64 public key from older API shapes.
        return 'v=DKIM1;k=rsa;t=s;s=email;p='.$txt;
    }

    /**
     * Create a Mailcow sync job (IMAP pull) for mailbox migration.
     *
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addSyncJob(array $attr): array
    {
        return $this->request('POST', '/api/v1/add/syncjob', $attr);
    }

    /**
     * @return array{success: bool, message: string, data?: mixed, password?: string}
     */
    public function addAppPassword(string $mailbox, string $appName, string $password): array
    {
        $response = $this->request('POST', '/api/v1/add/app-passwd', [
            'active' => '1',
            'username' => strtolower($mailbox),
            'app_name' => $appName,
            'app_passwd' => $password,
            'app_passwd2' => $password,
            'protocols' => [
                'imap_access',
                'smtp_access',
                'dav_access',
                'sieve_access',
            ],
        ]);

        if ($response['success']) {
            $response['password'] = $password;
        }

        return $response;
    }

    /**
     * @return array{success: bool, message: string, data?: list<array<string, mixed>>}
     */
    public function listAppPasswords(string $mailbox): array
    {
        $response = $this->request('GET', '/api/v1/get/app-passwd/all/'.rawurlencode(strtolower($mailbox)));
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data,
        ];
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function deleteAppPasswords(array $ids): array
    {
        $ids = array_values(array_filter(array_map(static fn ($id) => (string) $id, $ids)));
        if ($ids === []) {
            return ['success' => true, 'message' => 'Nothing to delete.'];
        }

        return $this->request('POST', '/api/v1/delete/app-passwd', $ids);
    }

    public function editDomainRatelimit(string $domain, int $rlValue, string $rlFrame = 'd'): array
    {
        $rlValue = max(1, $rlValue);
        $rlFrame = in_array($rlFrame, ['s', 'm', 'h', 'd'], true) ? $rlFrame : 'd';

        return $this->request('POST', '/api/v1/edit/rl-domain', [
            'items' => [strtolower($domain)],
            'attr' => [
                'rl_value' => (string) $rlValue,
                'rl_frame' => $rlFrame,
            ],
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: list<array<string, mixed>>}
     */
    public function listFilters(string $mailbox): array
    {
        $response = $this->request('GET', '/api/v1/get/filters/'.rawurlencode(strtolower($mailbox)));
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }

        return ['success' => true, 'message' => 'OK', 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function addFilter(array $attr): array
    {
        return $this->request('POST', '/api/v1/add/filter', $attr);
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function deleteFilters(array $ids): array
    {
        $ids = array_values(array_filter(array_map(static fn ($id) => (string) $id, $ids)));
        if ($ids === []) {
            return ['success' => true, 'message' => 'Nothing to delete.'];
        }

        return $this->request('POST', '/api/v1/delete/filter', $ids);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, message: string, data?: mixed}
     */
    public function request(string $method, string $path, array $body = []): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Mailcow is not configured for this node.'];
        }

        $url = $this->baseUrl().$path;
        $verify = $this->node->verify_ssl !== false;
        $options = ['verify' => $verify];
        if (config('mailcow.force_ipv4', true)) {
            // Prefer the IPv4 APP_IP that is typically on the Mailcow API allowlist.
            $options['force_ip_resolve'] = 'v4';
        }

        try {
            $client = Http::withHeaders([
                'X-API-Key' => $this->apiKey(),
                'Accept' => 'application/json',
            ])
                ->timeout(45)
                ->withOptions($options);

            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->asJson()->post($url, $body),
                'DELETE' => $client->delete($url),
                default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
            };

            $json = $response->json();
            $raw = $response->body();

            if ($response->failed()) {
                $message = $this->clarifyApiError(
                    $this->extractErrorMessage($json, $raw) ?: 'Mailcow API request failed (HTTP '.$response->status().').'
                );
                Log::warning('Mailcow API error', [
                    'node_id' => $this->node->id,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => mb_substr($raw, 0, 500),
                ]);

                return ['success' => false, 'message' => $message];
            }

            // Mailcow often returns ["object", "msg"] or nested type=success|danger
            if (is_array($json) && $this->looksLikeFailure($json)) {
                return [
                    'success' => false,
                    'message' => $this->clarifyApiError(
                        $this->extractErrorMessage($json, $raw) ?: 'Mailcow rejected the request.'
                    ),
                    'data' => $json,
                ];
            }

            return [
                'success' => true,
                'message' => 'OK',
                'data' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('Mailcow API exception', [
                'node_id' => $this->node->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  mixed  $json
     */
    private function looksLikeFailure($json): bool
    {
        if (! is_array($json)) {
            return false;
        }

        foreach ($json as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtolower((string) ($row['type'] ?? ''));
            if (in_array($type, ['danger', 'error'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $json
     */
    private function extractErrorMessage($json, string $raw): string
    {
        if (is_array($json)) {
            foreach ($json as $row) {
                if (is_array($row) && isset($row['msg'])) {
                    $msg = $row['msg'];
                    if (is_array($msg)) {
                        return implode(' ', array_map('strval', $msg));
                    }

                    return (string) $msg;
                }
            }
            if (isset($json['msg'])) {
                return is_array($json['msg']) ? implode(' ', $json['msg']) : (string) $json['msg'];
            }
            if (isset($json['message'])) {
                return (string) $json['message'];
            }
        }

        $trimmed = trim($raw);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 300) : '';
    }

    private function clarifyApiError(string $message): string
    {
        if (! str_contains(strtolower($message), 'api access denied')) {
            return $message;
        }

        return $message.' Add this app server IP (IPv4 and IPv6 if both are used) to Mailcow → Configuration → Access → API allowlist. Talksasa forces IPv4 for API calls when MAILCOW_FORCE_IPV4=true.';
    }
}
