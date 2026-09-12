<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Environment values a customer typed at checkout, held until the first
 * deploy creates the deployment row that owns them.
 *
 * They used to sit in service_meta['env_values'] in the clear, and every
 * later save mirrored the deployment's whole environment back into that key,
 * so the control-plane database carried two plaintext copies of each
 * DB_PASSWORD and API key for the life of the service. A pending-payment
 * order can wait days before it is deployed, which is exactly the window this
 * seed covers. It is encrypted the way ContainerGitCredentialsService stores
 * repository tokens, and it is cleared the moment the deployment row has taken
 * the values, so a deployed service carries no copy of its environment outside
 * container_deployments.env_values.
 */
class ContainerEnvironmentSeed
{
    public const META_KEY = 'env_values_encrypted';

    /**
     * The plaintext key this replaces. Read as a fallback so an order placed
     * before the backfill ran still deploys with its values. Never written.
     */
    public const LEGACY_META_KEY = 'env_values';

    /**
     * Ciphertext for a checkout payload, or null when there is nothing to keep.
     *
     * @param  array<mixed>  $values
     */
    public function encode(array $values): ?string
    {
        $values = $this->normalize($values);

        return $values === []
            ? null
            : Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $values
     */
    public function store(Service $service, array $values): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        unset($meta[self::LEGACY_META_KEY], $meta[self::META_KEY]);

        $encoded = $this->encode($values);
        if ($encoded !== null) {
            $meta[self::META_KEY] = $encoded;
        }

        $service->update(['service_meta' => $meta]);
    }

    /**
     * @return array<string, string>
     */
    public function read(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        $encrypted = $meta[self::META_KEY] ?? null;
        if (is_string($encrypted) && $encrypted !== '') {
            try {
                $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $this->normalize($decoded);
                }
            } catch (\Throwable $e) {
                // Almost always an APP_KEY rotation. The deploy goes ahead with
                // whatever else it has rather than failing on values the
                // customer can re-enter from the panel.
                Log::warning('Checkout environment seed could not be decrypted', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $legacy = $meta[self::LEGACY_META_KEY] ?? null;

        return is_array($legacy) ? $this->normalize($legacy) : [];
    }

    public function clear(Service $service): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! array_key_exists(self::META_KEY, $meta) && ! array_key_exists(self::LEGACY_META_KEY, $meta)) {
            return;
        }

        unset($meta[self::META_KEY], $meta[self::LEGACY_META_KEY]);
        $service->update(['service_meta' => $meta]);
    }

    /**
     * Keys are names, values are strings. Nested input from a form is dropped
     * rather than stringified into "Array".
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private function normalize(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || ! is_scalar($value)) {
                continue;
            }

            $normalized[$key] = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }

        return $normalized;
    }
}
