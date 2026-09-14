<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official WordPress core checksums, fetched by the platform (not from inside
 * the customer's container) and cached on the node so the scanner can name a
 * modified or foreign core file with certainty.
 */
class WordPressCoreChecksumService
{
    private const API = 'https://api.wordpress.org/core/checksums/1.0/';

    public const RELEASE_BASE = 'https://wordpress.org/';

    /**
     * @return array{version: string, locale: string, checksums: array<string, string>}|null
     */
    public function manifest(string $version, string $locale = 'en_US'): ?array
    {
        $version = $this->cleanVersion($version);
        if ($version === null) {
            return null;
        }
        $locale = preg_match('/^[a-zA-Z_]{2,10}$/', $locale) === 1 ? $locale : 'en_US';

        $key = 'wp-core-checksums:'.$version.':'.$locale;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        foreach (array_unique([$locale, 'en_US']) as $candidate) {
            $fetched = $this->fetch($version, $candidate);
            if ($fetched !== null) {
                $result = ['version' => $version, 'locale' => $candidate, 'checksums' => $fetched];
                Cache::put($key, $result, now()->addDay());

                return $result;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function fetch(string $version, string $locale): ?array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)->acceptJson()->get(self::API, ['version' => $version, 'locale' => $locale]);
        } catch (\Throwable $e) {
            Log::warning('WordPress checksum fetch failed', ['version' => $version, 'locale' => $locale, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->ok()) {
            return null;
        }
        $checksums = $response->json('checksums');
        if (! is_array($checksums) || $checksums === []) {
            return null;
        }
        $clean = [];
        foreach ($checksums as $path => $hash) {
            if (is_string($path) && is_string($hash) && preg_match('/^[0-9a-f]{32}$/', $hash) === 1) {
                $clean[$path] = $hash;
            }
        }

        return $clean === [] ? null : $clean;
    }

    public function cleanVersion(?string $version): ?string
    {
        $version = trim((string) $version);

        return preg_match('/^\d+\.\d+(\.\d+)?$/', $version) === 1 ? $version : null;
    }

    /**
     * Read the installed version and locale from the host bind mount.
     *
     * @return array{version: ?string, locale: string}
     */
    public function installedVersionCommand(string $hostAppPath): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));

        // Single quotes on the shell side: "$wp_version" would otherwise be expanded to nothing.
        return 'grep -E \'^\\$wp_version = \' '.$root.'/wp-includes/version.php 2>/dev/null | head -n 1; '
            .'grep -E \'define\\s*\\(\\s*[\'"\'"\'"]WPLANG[\'"\'"\'"]\' '.$root.'/wp-config.php 2>/dev/null | head -n 1; true';
    }

    public function installedVersion(SSHService $ssh, string $hostAppPath): array
    {
        $out = (string) $ssh->exec(
            $this->installedVersionCommand($hostAppPath),
            20
        );
        $version = null;
        $locale = 'en_US';
        if (preg_match("/wp_version\s*=\s*['\"]([0-9.]+)['\"]/", $out, $m) === 1) {
            $version = $this->cleanVersion($m[1]);
        }
        if (preg_match("/WPLANG['\"]\s*,\s*['\"]([a-zA-Z_]{2,10})['\"]/", $out, $m) === 1) {
            $locale = $m[1];
        }

        return ['version' => $version, 'locale' => $locale];
    }

    /**
     * Write the manifest to the node's shared tools cache once per version so
     * every site on the node can be verified without another download.
     * Returns the remote path, or null when no manifest is available.
     */
    public function ensureManifestOnNode(SSHService $ssh, string $version, string $locale = 'en_US'): ?string
    {
        $manifest = $this->manifest($version, $locale);
        if ($manifest === null) {
            return null;
        }
        $remote = $this->manifestHostPath($manifest['version'], $manifest['locale']);
        $probe = trim((string) $ssh->exec('[ -s '.escapeshellarg($remote).' ] && head -c 40 '.escapeshellarg($remote).' | grep -q checksums && echo present || echo absent', 15));
        if ($probe === 'present') {
            return $remote;
        }
        $ssh->exec('mkdir -p '.escapeshellarg(dirname($remote)).' && chmod 755 '.escapeshellarg(dirname($remote)), 15);
        $ssh->upload((string) json_encode(['version' => $manifest['version'], 'checksums' => $manifest['checksums']]), $remote);

        return $remote;
    }

    public function manifestHostPath(string $version, string $locale): string
    {
        return $this->cacheDir().'/wp-checksums-'.$version.'-'.$locale.'.json';
    }

    public function cacheDir(): string
    {
        return app(WordPressContainerHardeningService::class)->toolsHostPath().'/cache';
    }

    /**
     * Download the official no-content release once per node and verify it
     * against the published SHA-1 before it is trusted.
     */
    public function ensureReleaseArchiveCommand(string $version): string
    {
        $dir = escapeshellarg($this->cacheDir());
        $file = escapeshellarg($this->cacheDir().'/wordpress-'.$version.'-no-content.zip');
        $url = escapeshellarg(self::RELEASE_BASE.'wordpress-'.$version.'-no-content.zip');
        $shaUrl = escapeshellarg(self::RELEASE_BASE.'wordpress-'.$version.'-no-content.zip.sha1');

        return 'dir='.$dir.'; f='.$file.'; url='.$url.'; sha='.$shaUrl.'; mkdir -p "$dir"; '
            .'if [ -s "$f" ] && [ -s "$f.sha1" ] && [ "$(sha1sum "$f" | cut -d" " -f1)" = "$(tr -d "[:space:]" < "$f.sha1")" ]; then echo "release ready"; exit 0; fi; '
            .'curl -fsSL --max-time 120 -o "$f.sha1.tmp" "$sha" && curl -fsSL --max-time 300 -o "$f.tmp" "$url" || { rm -f "$f.tmp" "$f.sha1.tmp"; echo "download failed"; exit 1; }; '
            .'if [ "$(sha1sum "$f.tmp" | cut -d" " -f1)" = "$(tr -d "[:space:]" < "$f.sha1.tmp")" ]; then mv -f "$f.tmp" "$f" && mv -f "$f.sha1.tmp" "$f.sha1" && echo "release ready"; else rm -f "$f.tmp" "$f.sha1.tmp"; echo "checksum mismatch"; exit 1; fi';
    }

    /**
     * Copy the named core files from the verified release archive over the
     * site, touching nothing else. Prints one "restored <path>" line per file
     * and "__RESTORED__:<n>" at the end.
     *
     * @param  list<string>  $relPaths
     */
    public function restoreFilesCommand(string $version, string $hostAppPath, array $relPaths): string
    {
        $archive = escapeshellarg($this->cacheDir().'/wordpress-'.$version.'-no-content.zip');
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $args = implode(' ', array_map('escapeshellarg', array_values($relPaths)));

        return 'python3 - '.$archive.' '.$root.' '.$args." <<'TALKSASA_PY'\n".$this->restoreScript()."\nTALKSASA_PY\n";
    }

    public function restoreScript(): string
    {
        return <<<'PY'
import sys, os, zipfile, shutil
archive, root = sys.argv[1], sys.argv[2]
wanted = sys.argv[3:]
restored = 0
with zipfile.ZipFile(archive) as z:
    names = set(z.namelist())
    for rel in wanted:
        if rel.startswith('/') or '..' in rel.split('/'):
            continue
        member = 'wordpress/' + rel
        if member not in names:
            print('absent ' + rel)
            continue
        dest = os.path.join(root, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        with z.open(member) as src, open(dest + '.talksasa-restore', 'wb') as out:
            shutil.copyfileobj(src, out)
        os.replace(dest + '.talksasa-restore', dest)
        os.chown(dest, 33, 33)
        os.chmod(dest, 0o644)
        restored += 1
        print('restored ' + rel)
print('__RESTORED__:%d' % restored)
PY;
    }
}
