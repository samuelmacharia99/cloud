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
        $response = $this->request('GET', '/api/v1/get/dkim/'.$domain);
        if (! $response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];
        $txt = '';
        $selector = 'dkim';

        if (is_string($data)) {
            $txt = $data;
        } elseif (is_array($data)) {
            $txt = (string) ($data['dkim_txt'] ?? $data['pubkey'] ?? $data['txt'] ?? '');
            $selector = (string) ($data['dkim_selector'] ?? $data['selector'] ?? 'dkim');
            if ($txt === '' && isset($data['dkim_txt'])) {
                $txt = (string) $data['dkim_txt'];
            }
            // Some versions return { domain: { dkim_txt: ... } }
            if ($txt === '' && isset($data[$domain]) && is_array($data[$domain])) {
                $txt = (string) ($data[$domain]['dkim_txt'] ?? '');
                $selector = (string) ($data[$domain]['dkim_selector'] ?? $selector);
            }
        }

        return [
            'success' => true,
            'message' => 'OK',
            'dkim_txt' => $txt,
            'selector' => $selector,
            'data' => $data,
        ];
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

        try {
            $client = Http::withHeaders([
                'X-API-Key' => $this->apiKey(),
                'Accept' => 'application/json',
            ])
                ->timeout(45)
                ->withOptions(['verify' => $verify]);

            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->asJson()->post($url, $body),
                'DELETE' => $client->delete($url),
                default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
            };

            $json = $response->json();
            $raw = $response->body();

            if ($response->failed()) {
                $message = $this->extractErrorMessage($json, $raw) ?: 'Mailcow API request failed (HTTP '.$response->status().').';
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
                    'message' => $this->extractErrorMessage($json, $raw) ?: 'Mailcow rejected the request.',
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
}
