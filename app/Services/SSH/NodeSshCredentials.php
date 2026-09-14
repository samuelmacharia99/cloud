<?php

namespace App\Services\SSH;

use App\Models\Node;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Which secrets a node can log in with, in the order to try them.
 *
 * A node authenticates with a password or a private key. The chosen method is
 * tried first; the other secret, if one is stored, is a fallback so a node in
 * the middle of a key rollout keeps working. The DirectAdmin login key column
 * was once overloaded as an SSH key on container hosts; it is still accepted
 * when it holds PEM/OpenSSH material so those nodes do not lock out.
 */
class NodeSshCredentials
{
    public const METHOD_PASSWORD = 'password';

    public const METHOD_KEY = 'key';

    /**
     * Ordered login secrets: strings are passwords, objects are loaded keys.
     *
     * @return list<string|PrivateKey>
     *
     * @throws \InvalidArgumentException when a stored key cannot be parsed
     */
    public static function loginCandidates(Node $node): array
    {
        $candidates = [];
        $key = self::storedPrivateKey($node);
        $password = (string) ($node->ssh_password ?? '');

        $loadedKey = null;
        if ($key !== null) {
            $loadedKey = self::loadPrivateKey($key, (string) ($node->ssh_key_passphrase ?? ''));
        }

        if ($node->sshAuthMethod() === self::METHOD_KEY) {
            if ($loadedKey) {
                $candidates[] = $loadedKey;
            }
            if ($password !== '') {
                $candidates[] = $password;
            }
        } else {
            if ($password !== '') {
                $candidates[] = $password;
            }
            if ($loadedKey) {
                $candidates[] = $loadedKey;
            }
        }

        return $candidates;
    }

    /**
     * The private key material on the node record, dedicated column first,
     * then the legacy DirectAdmin login key column when it holds a key.
     */
    public static function storedPrivateKey(Node $node): ?string
    {
        $dedicated = trim((string) ($node->ssh_private_key ?? ''));
        if ($dedicated !== '') {
            return $dedicated;
        }

        $legacy = trim((string) ($node->da_login_key ?? ''));
        if ($legacy !== '' && self::looksLikePrivateKey($legacy)) {
            return $legacy;
        }

        return null;
    }

    public static function looksLikePrivateKey(string $material): bool
    {
        return str_contains($material, 'PRIVATE KEY') || str_starts_with(trim($material), 'PuTTY-User-Key-File');
    }

    /**
     * @throws \InvalidArgumentException with an operator-readable reason
     */
    public static function loadPrivateKey(string $material, string $passphrase = ''): PrivateKey
    {
        try {
            $loaded = PublicKeyLoader::load(trim($material), $passphrase !== '' ? $passphrase : false);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('SSH key format invalid: '.$e->getMessage(), 0, $e);
        }

        if (! $loaded instanceof PrivateKey) {
            throw new \InvalidArgumentException('SSH key format invalid: this is a public key, not a private key.');
        }

        return $loaded;
    }

    /**
     * Short public fingerprint for showing which key is on file, or null when none loads.
     */
    public static function fingerprint(Node $node): ?string
    {
        $material = self::storedPrivateKey($node);
        if ($material === null) {
            return null;
        }

        try {
            $key = self::loadPrivateKey($material, (string) ($node->ssh_key_passphrase ?? ''));
            $public = $key->getPublicKey();

            return method_exists($public, 'getFingerprint')
                ? (string) $public->getFingerprint('sha256')
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
