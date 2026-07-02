<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Crypt;

class ContainerGitCredentialsService
{
    public const REPO_TOKEN_META_KEY = 'source_repo_token_encrypted';

    public const COMPOSER_AUTH_META_KEY = 'composer_auth_encrypted';

    /**
     * @return array{url: string, branch: string, has_repo_token: bool, has_composer_auth: bool}
     */
    public function repositorySettings(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        return [
            'url' => trim((string) ($meta['source_repo_url'] ?? '')),
            'branch' => trim((string) ($meta['source_repo_branch'] ?? 'main')) ?: 'main',
            'has_repo_token' => $this->hasRepositoryToken($service),
            'has_composer_auth' => $this->hasComposerAuth($service),
        ];
    }

    public function applyConnection(
        Service $service,
        string $repoUrl,
        string $branch,
        ?string $repoToken = null,
        ?string $composerGithubToken = null,
        bool $removeRepoToken = false,
        bool $removeComposerAuth = false,
    ): void {
        $repoUrl = app(ContainerGitRepositoryService::class)->normalizeRepositoryUrl($repoUrl);
        $branch = app(ContainerGitRepositoryService::class)->normalizeBranch($branch);

        [$cleanUrl, $embeddedToken] = $this->stripUrlCredentials($repoUrl);
        $tokenToStore = $removeRepoToken ? null : ($repoToken !== null && $repoToken !== '' ? $repoToken : $embeddedToken);

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['source_repo_url'] = $cleanUrl;
        $meta['source_repo_branch'] = $branch;
        $meta['source_repo_connected_at'] = now()->toIso8601String();

        if ($removeRepoToken) {
            unset($meta[self::REPO_TOKEN_META_KEY]);
        } elseif ($tokenToStore !== null && $tokenToStore !== '') {
            $meta[self::REPO_TOKEN_META_KEY] = Crypt::encryptString($tokenToStore);
        }

        if ($removeComposerAuth) {
            unset($meta[self::COMPOSER_AUTH_META_KEY]);
        } elseif ($composerGithubToken !== null && $composerGithubToken !== '') {
            $meta[self::COMPOSER_AUTH_META_KEY] = Crypt::encryptString($this->buildGithubComposerAuthJson($composerGithubToken));
        }

        $service->update(['service_meta' => $meta]);
    }

    public function hasRepositoryToken(Service $service): bool
    {
        return $this->decryptRepositoryToken($service) !== null;
    }

    public function hasComposerAuth(Service $service): bool
    {
        return $this->decryptComposerAuth($service) !== null;
    }

    public function authenticatedCloneUrl(Service $service, string $normalizedHttpsUrl): string
    {
        $token = $this->decryptRepositoryToken($service);
        if ($token === null || $token === '') {
            return $normalizedHttpsUrl;
        }

        [$cleanUrl] = $this->stripUrlCredentials($normalizedHttpsUrl);

        return $this->injectHttpsCredentials($cleanUrl, 'x-access-token', $token);
    }

    public function composerAuthShellExport(Service $service): string
    {
        $auth = $this->decryptComposerAuth($service);
        if ($auth === null || $auth === '') {
            return '';
        }

        return 'export COMPOSER_AUTH='.escapeshellarg($auth).'; ';
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    public function stripUrlCredentials(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return [$url, null];
        }

        $token = isset($parts['pass']) ? (string) $parts['pass'] : null;
        if ($token === null && isset($parts['user']) && $parts['user'] !== '') {
            $token = (string) $parts['user'];
        }

        unset($parts['user'], $parts['pass']);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return [$scheme.'://'.$host.$port.$path.$query.$fragment, $token !== '' ? $token : null];
    }

    public function maskRepositoryUrl(string $url): string
    {
        [$cleanUrl] = $this->stripUrlCredentials($url);
        $parts = parse_url($cleanUrl);
        if (! is_array($parts)) {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.$host.$path.$query;
    }

    private function injectHttpsCredentials(string $url, string $username, string $token): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.rawurlencode($username).':'.rawurlencode($token).'@'.$host.$port.$path.$query;
    }

    private function buildGithubComposerAuthJson(string $token): string
    {
        return json_encode([
            'github-oauth' => [
                'github.com' => $token,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function decryptRepositoryToken(Service $service): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $encrypted = $meta[self::REPO_TOKEN_META_KEY] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decryptComposerAuth(Service $service): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $encrypted = $meta[self::COMPOSER_AUTH_META_KEY] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
