<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

/**
 * After Paths.php and the sidecar hostname are correct, CI4 still 500s when
 * encryption.key / app.baseURL / writable/ were never set for this container.
 */
class PhpCodeIgniterRuntimeHealer
{
    public function generateEncryptionKey(): string
    {
        return 'hex2bin:'.bin2hex(random_bytes(32));
    }

    public function encryptionKeyIsMissing(string $env): bool
    {
        if (preg_match('/^encryption\.key[ \t]*=[ \t]*(.*)$/m', $env, $matches) !== 1) {
            return true;
        }

        $value = trim($matches[1], " \t\"'");

        return $value === '' || strcasecmp($value, 'hex2bin:') === 0;
    }

    public function baseUrlLooksLocal(string $env): bool
    {
        if (preg_match('/^app\.baseURL[ \t]*=[ \t]*(.*)$/m', $env, $matches) !== 1) {
            return true;
        }

        $value = strtolower(trim($matches[1], " \t\"'"));

        return $value === ''
            || str_contains($value, 'localhost')
            || str_contains($value, '127.0.0.1');
    }

    public function healEnv(string $env, string $publicUrl = ''): string
    {
        if ($this->encryptionKeyIsMissing($env)) {
            $env = $this->upsertEnv($env, 'encryption.key', $this->generateEncryptionKey());
        }

        $base = $this->normalizeBaseUrl($publicUrl);
        if ($base !== '' && $this->baseUrlLooksLocal($env)) {
            $env = $this->upsertEnv($env, 'app.baseURL', $base);
        }

        $hosts = $this->allowedHostnamesLine($publicUrl);
        if ($hosts !== '' && $this->allowedHostnamesNeedUpdate($env, $publicUrl)) {
            $env = $this->upsertEnv($env, 'app.allowedHostnames', $hosts);
            $env = $this->upsertEnv($env, 'ALLOWED_HOSTNAMES', $hosts);
        }

        return $env === '' || str_ends_with($env, "\n") ? $env : $env."\n";
    }

    /**
     * Official Open Source POS fatals in production when this whitelist is empty
     * (GHSA-jchf-7hr6-h4f3 / “Server Error” with an empty HTTP body).
     */
    public function allowedHostnamesMissing(string $env): bool
    {
        return $this->currentAllowedHostnames($env) === [];
    }

    public function hostnamesFromPublicUrl(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : '';
    }

    /**
     * @return list<string>
     */
    public function allowedHostnameList(string $publicUrl): array
    {
        $hosts = [];
        $public = $this->hostnamesFromPublicUrl($publicUrl);
        if ($public !== '') {
            $hosts[] = $public;
            if (! str_starts_with($public, 'www.')) {
                $hosts[] = 'www.'.$public;
            }
        }
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';

        return array_values(array_unique($hosts));
    }

    public function allowedHostnamesLine(string $publicUrl): string
    {
        return implode(',', $this->allowedHostnameList($publicUrl));
    }

    public function currentAllowedHostnames(string $env): array
    {
        foreach (['ALLOWED_HOSTNAMES', 'app.allowedHostnames'] as $key) {
            if (preg_match('/^'.preg_quote($key, '/').'[ \t]*=[ \t]*(.*)$/m', $env, $matches) !== 1) {
                continue;
            }
            $value = trim($matches[1], " \t\"'");
            if ($value === '') {
                continue;
            }

            return array_values(array_filter(array_map(
                static fn (string $host): string => strtolower(trim($host)),
                explode(',', $value)
            )));
        }

        return [];
    }

    public function allowedHostnamesNeedUpdate(string $env, string $publicUrl = ''): bool
    {
        if ($this->envKeyMissingOrEmpty($env, 'ALLOWED_HOSTNAMES')
            || $this->envKeyMissingOrEmpty($env, 'app.allowedHostnames')) {
            return true;
        }

        $current = $this->currentAllowedHostnames($env);
        if ($current === []) {
            return true;
        }

        $needed = $this->hostnamesFromPublicUrl($publicUrl);

        return $needed !== '' && ! in_array($needed, $current, true);
    }

    public function envKeyMissingOrEmpty(string $env, string $key): bool
    {
        if (preg_match('/^'.preg_quote($key, '/').'[ \t]*=[ \t]*(.*)$/m', $env, $matches) !== 1) {
            return true;
        }

        return trim($matches[1], " \t\"'") === '';
    }

    public function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return rtrim($url, '/').'/';
    }

    /**
     * Stock CodeIgniter uses MySQLi, not PDO. Doctor’s live PDO probe can
     * succeed while GET / 500s because ext-mysqli is optional on php-runtime.
     */
    public function usesMysqliDriver(string $databasePhp = '', string $env = ''): bool
    {
        $haystack = $databasePhp."\n".$env;
        if (preg_match('/(?:database\.default\.DBDriver|[\'"]DBDriver[\'"]\s*=>)\s*[\'"]\s*([^\'"]+)/i', $haystack, $matches) === 1
            || preg_match('/^database\.default\.DBDriver[ \t]*=[ \t]*(.+)$/m', $env, $matches) === 1) {
            $driver = strtolower(trim($matches[1], " \t\"'"));

            return $driver === '' || str_contains($driver, 'mysqli') || $driver === 'mysql';
        }

        return true;
    }

    public function applyOnHost(SSHService $ssh, string $hostAppPath, string $publicUrl = ''): int
    {
        $changed = 0;
        $root = rtrim($hostAppPath, '/');
        app(PhpCodeIgniterPathFixer::class)->ensureWritableOnHost($ssh, $root);

        try {
            $ssh->exec(
                'if [ ! -f '.escapeshellarg($root.'/.env')
                .' ] && [ -f '.escapeshellarg($root.'/.env.example')
                .' ]; then cp '.escapeshellarg($root.'/.env.example')
                .' '.escapeshellarg($root.'/.env').'; fi',
                10
            );
        } catch (\Throwable) {
        }

        foreach ([$root.'/.env', $root.'/app/.env'] as $envPath) {
            try {
                $exists = trim($ssh->exec('test -f '.escapeshellarg($envPath).' && echo yes || echo no', 10));
                if ($exists !== 'yes') {
                    continue;
                }
                $original = $ssh->downloadFile($envPath);
                $updated = $this->healEnv($original, $publicUrl);
                if ($updated !== $original) {
                    $ssh->upload($updated, $envPath);
                    $changed++;
                }
            } catch (\Throwable) {
            }
        }

        return $changed;
    }

    public function upsertEnv(string $text, string $key, string $value): string
    {
        $line = $key.'='.$this->encodeEnvValue($value);
        $pattern = '/^'.preg_quote($key, '/').'[ \t]*=.*$/m';
        if (preg_match($pattern, $text) === 1) {
            return (string) preg_replace($pattern, $line, $text, 1);
        }

        return rtrim($text)."\n".$line."\n";
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\$]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
