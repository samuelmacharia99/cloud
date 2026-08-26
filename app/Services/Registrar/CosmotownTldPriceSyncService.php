<?php

namespace App\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Currency;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Services\AdminActivityService;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Cosmotown\CosmotownTldPrice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CosmotownTldPriceSyncService
{
    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced: int,
     *     unchanged: int,
     *     skipped: int,
     *     errors: int,
     *     failures: list<array{extension: string, error: string}>
     * }
     */
    public function sync(?Registrar $registrar = null): array
    {
        $registrar ??= app(CosmotownInventorySyncService::class)->activeCosmotownRegistrar();

        if (! $registrar) {
            return $this->result(
                success: false,
                message: 'No active Cosmotown registrar is configured.',
            );
        }

        if ($registrar->driver !== RegistrarDriver::Cosmotown) {
            return $this->result(
                success: false,
                message: 'TLD price sync is only available for Cosmotown.',
            );
        }

        $client = CosmotownClient::forRegistrar($registrar);
        $extensions = DomainExtension::query()
            ->where('enabled', true)
            ->orderBy('extension')
            ->get();

        if ($extensions->isEmpty()) {
            return $this->result(
                success: true,
                message: 'No enabled domain extensions to sync.',
            );
        }

        $synced = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = 0;
        $failures = [];

        foreach ($extensions as $extension) {
            $tld = CosmotownTldPrice::normalizeTld($extension->extension);

            if ($tld === '') {
                $skipped++;

                continue;
            }

            try {
                $price = $client->getTldPrice($tld);
                $changed = $this->applyPrice($extension, $price);

                if ($changed) {
                    $synced++;
                } else {
                    $unchanged++;
                }
            } catch (CosmotownException $e) {
                $errors++;
                $failures[] = [
                    'extension' => $extension->extension,
                    'error' => $e->getMessage(),
                ];

                Log::warning('Cosmotown TLD price sync failed for extension', [
                    'extension' => $extension->extension,
                    'tld' => $tld,
                    'error' => $e->getMessage(),
                    'http_status' => $e->httpStatus,
                    'response_keys' => array_keys($e->response ?? []),
                    'response' => $this->summarizePayload($e->response),
                ]);
            }
        }

        if ($synced > 0 && Auth::check()) {
            AdminActivityService::log(
                'cosmotown_tld_price_sync',
                "Synced Cosmotown registrar costs for {$synced} TLD(s).",
                $registrar,
                [
                    'synced' => $synced,
                    'unchanged' => $unchanged,
                    'errors' => $errors,
                ]
            );
        }

        $message = match (true) {
            $synced > 0 && $errors === 0 => "Updated Cosmotown registrar costs for {$synced} TLD(s). {$unchanged} unchanged.",
            $synced > 0 => "Updated {$synced} TLD(s). {$errors} failed — check logs for unsupported extensions.",
            $errors > 0 && $unchanged === 0 => 'Could not fetch Cosmotown TLD prices. '.$failures[0]['error'],
            $errors > 0 => "No price changes. {$errors} TLD(s) failed at Cosmotown.",
            default => "All {$unchanged} TLD costs already match Cosmotown.",
        };

        return $this->result(
            success: $errors === 0 || $synced > 0,
            message: $message,
            synced: $synced,
            unchanged: $unchanged,
            skipped: $skipped,
            errors: $errors,
            failures: array_slice($failures, 0, 20),
        );
    }

    private function applyPrice(DomainExtension $extension, CosmotownTldPrice $price): bool
    {
        $registerKes = $this->toKes($price->registerUsd, $price->currency);
        $renewKes = $this->toKes($price->renewUsd, $price->currency);
        $transferKes = $this->toKes($price->transferUsd, $price->currency);
        $syncedAt = now();

        $updates = [
            'registrar_register_cost_usd' => $price->registerUsd,
            'registrar_renew_cost_usd' => $price->renewUsd,
            'registrar_transfer_cost_usd' => $price->transferUsd,
            'registrar_register_cost_kes' => $registerKes,
            'registrar_renew_cost_kes' => $renewKes,
            'registrar_transfer_cost_kes' => $transferKes,
            'registrar_cost_synced_at' => $syncedAt,
        ];

        $changed = false;

        foreach ($updates as $field => $value) {
            if ($field === 'registrar_cost_synced_at') {
                continue;
            }

            $current = $extension->{$field};

            if ($value === null && $current === null) {
                continue;
            }

            if ($value === null || $current === null) {
                $changed = true;

                continue;
            }

            if (round((float) $current, 2) !== round((float) $value, 2)) {
                $changed = true;
            }
        }

        if (! $changed) {
            $extension->forceFill(['registrar_cost_synced_at' => $syncedAt])->save();

            return false;
        }

        $extension->update($updates);

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function summarizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $json = json_encode($payload);
        if ($json !== false && strlen($json) > 4000) {
            return [
                '_truncated' => true,
                'preview' => substr($json, 0, 4000),
            ];
        }

        return $payload;
    }

    private function toKes(?float $amount, string $currency): ?float
    {
        if ($amount === null) {
            return null;
        }

        if (strtoupper($currency) === 'KES') {
            return round($amount, 2);
        }

        $from = Currency::query()->where('code', strtoupper($currency))->first();

        if (! $from) {
            Log::warning('Cosmotown TLD price currency missing from catalog; storing USD amount as KES fallback.', [
                'currency' => $currency,
                'amount' => $amount,
            ]);

            return round($amount, 2);
        }

        return round($from->convertToKES($amount), 2);
    }

    /**
     * @param  list<array{extension: string, error: string}>  $failures
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced: int,
     *     unchanged: int,
     *     skipped: int,
     *     errors: int,
     *     failures: list<array{extension: string, error: string}>
     * }
     */
    private function result(
        bool $success,
        string $message,
        int $synced = 0,
        int $unchanged = 0,
        int $skipped = 0,
        int $errors = 0,
        array $failures = [],
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'synced' => $synced,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'errors' => $errors,
            'failures' => $failures,
        ];
    }
}
