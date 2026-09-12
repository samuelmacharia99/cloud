<?php

namespace App\Services\Provisioning;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-time move of container secrets from plaintext to ciphertext at rest.
 *
 * Three columns gain encrypted casts on their models; this rewrites the rows
 * those casts will read. It also retires the plaintext copy of every
 * deployment's environment that lived in services.service_meta['env_values']:
 * a deployed service keeps its values in container_deployments.env_values
 * only, and an undeployed one keeps them in the encrypted checkout seed.
 *
 * Every step is idempotent. A value that already decrypts is left alone and a
 * service without the mirror key is skipped, so the migration that calls this
 * can be re-run, and a partial run can be resumed, without double-encrypting
 * anything. Lives outside the migration so it can be tested against real rows.
 */
class ContainerSecretsAtRestBackfill
{
    private const CHUNK = 500;

    /**
     * @return array{encrypted: array<string, int>, mirrors_folded: int, seeds_created: int}
     */
    public function run(): array
    {
        $encrypted = [
            'container_deployments.env_values' => $this->encryptColumn('container_deployments', 'env_values'),
            'container_deployments.docker_compose_content' => $this->encryptColumn('container_deployments', 'docker_compose_content'),
            'services.credentials' => $this->encryptColumn('services', 'credentials'),
        ];

        [$folded, $seeded] = $this->foldEnvironmentMirror();

        return [
            'encrypted' => $encrypted,
            'mirrors_folded' => $folded,
            'seeds_created' => $seeded,
        ];
    }

    private function encryptColumn(string $table, string $column): int
    {
        $count = 0;

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$count, $table, $column): void {
                foreach ($rows as $row) {
                    $raw = (string) $row->{$column};
                    if ($this->isEncrypted($raw)) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => Crypt::encryptString($raw)]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function foldEnvironmentMirror(): array
    {
        $folded = 0;
        $seeded = 0;

        DB::table('services')
            ->whereNotNull('service_meta')
            ->select(['id', 'service_meta'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($services) use (&$folded, &$seeded): void {
                foreach ($services as $service) {
                    $meta = is_string($service->service_meta)
                        ? json_decode($service->service_meta, true)
                        : $service->service_meta;
                    if (! is_array($meta) || ! array_key_exists(ContainerEnvironmentSeed::LEGACY_META_KEY, $meta)) {
                        continue;
                    }

                    $mirror = $meta[ContainerEnvironmentSeed::LEGACY_META_KEY];
                    $mirror = is_array($mirror) ? $mirror : [];
                    unset($meta[ContainerEnvironmentSeed::LEGACY_META_KEY]);

                    $deployment = DB::table('container_deployments')
                        ->where('service_id', $service->id)
                        ->where('status', '!=', 'terminated')
                        ->orderByDesc('id')
                        ->first(['id', 'env_values']);

                    if ($deployment) {
                        // The deployment row is authoritative. The mirror only
                        // fills keys the row never received, which is what
                        // the deploy-time merge did with it anyway.
                        $current = $this->decodeEnvironment($deployment->env_values);
                        $merged = array_merge($mirror, $current);
                        if ($merged !== $current) {
                            DB::table('container_deployments')
                                ->where('id', $deployment->id)
                                ->update(['env_values' => Crypt::encryptString(json_encode($merged, JSON_THROW_ON_ERROR))]);
                        }
                    } elseif ($mirror !== []) {
                        $meta[ContainerEnvironmentSeed::META_KEY] = Crypt::encryptString(
                            json_encode($mirror, JSON_THROW_ON_ERROR)
                        );
                        $seeded++;
                    }

                    DB::table('services')
                        ->where('id', $service->id)
                        ->update(['service_meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
                    $folded++;
                }
            });

        return [$folded, $seeded];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEnvironment(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $raw = Crypt::decryptString($raw);
        } catch (\Throwable) {
            // Still plaintext JSON from before the column was encrypted.
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
