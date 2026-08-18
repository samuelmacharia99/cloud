<?php

namespace App\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\Registrar;
use App\Services\AdminActivityService;
use App\Services\InvoiceGenerationScheduleService;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Drivers\CosmotownRegistrarDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Overlay Cosmotown live expiry and nameservers onto domains already in Admin → Domains.
 * Does not create customer records for names that are only at Cosmotown.
 */
class CosmotownInventorySyncService
{
    public function __construct(
        private InvoiceGenerationScheduleService $invoiceSchedule,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     fetched: int,
     *     updated: int,
     *     unchanged: int,
     *     unmatched_count: int,
     *     unmatched: list<string>,
     *     errors: int
     * }
     */
    public function sync(?Registrar $registrar = null): array
    {
        $registrar ??= $this->activeCosmotownRegistrar();

        if (! $registrar) {
            return $this->result(
                success: false,
                message: 'No active Cosmotown registrar is configured.',
            );
        }

        if ($registrar->driver !== RegistrarDriver::Cosmotown) {
            return $this->result(
                success: false,
                message: 'Inventory sync is only available for Cosmotown.',
            );
        }

        try {
            $remote = CosmotownClient::forRegistrar($registrar)->listAllDomains();
        } catch (CosmotownException $e) {
            Log::error('Cosmotown inventory list failed', [
                'registrar_id' => $registrar->id,
                'error' => $e->getMessage(),
            ]);

            return $this->result(
                success: false,
                message: $e->getMessage(),
            );
        }

        $local = $this->localByFqdn();
        $updated = 0;
        $unchanged = 0;
        $errors = 0;
        $unmatched = [];

        foreach ($remote as $row) {
            $fqdn = $this->fqdnFromRow($row);
            if ($fqdn === '') {
                $errors++;

                continue;
            }

            $matches = $local->get($fqdn, collect());
            if ($matches->isEmpty()) {
                $unmatched[] = $fqdn;

                continue;
            }

            $info = $this->domainInfo($registrar, $fqdn, $row);

            foreach ($matches as $domain) {
                try {
                    if ($this->applyRemoteData($domain, $registrar, $fqdn, $row, $info)) {
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('Cosmotown inventory update failed', [
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $unmatchedCount = count($unmatched);
        $message = "Cosmotown inventory: {$updated} updated, {$unchanged} already current, {$unmatchedCount} not in Admin → Domains.";
        if ($errors > 0) {
            $message .= " {$errors} failed.";
        }

        if (Auth::check()) {
            AdminActivityService::log(
                'cosmotown_inventory_sync',
                $message,
                $registrar,
                [
                    'fetched' => count($remote),
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                    'unmatched_count' => $unmatchedCount,
                    'unmatched' => array_slice($unmatched, 0, 50),
                    'errors' => $errors,
                    'environment' => $registrar->environment,
                ],
            );
        }

        return $this->result(
            success: $errors === 0,
            message: $message,
            fetched: count($remote),
            updated: $updated,
            unchanged: $unchanged,
            unmatchedCount: $unmatchedCount,
            unmatched: array_slice($unmatched, 0, 20),
            errors: $errors,
        );
    }

    public function activeCosmotownRegistrar(): ?Registrar
    {
        return Registrar::query()
            ->where('driver', RegistrarDriver::Cosmotown)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->ordered()
            ->first();
    }

    /**
     * @return Collection<string, Collection<int, Domain>>
     */
    private function localByFqdn(): Collection
    {
        return Domain::query()
            ->get()
            ->groupBy(fn (Domain $domain) => strtolower($domain->fqdn()));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fqdnFromRow(array $row): string
    {
        return strtolower(trim((string) ($row['domain'] ?? $row['name'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function domainInfo(Registrar $registrar, string $fqdn, array $row): array
    {
        $nameservers = $this->nameserversFrom($row);
        if (count($nameservers) >= 2 && $this->expirationFrom($row) !== null) {
            return $row;
        }

        try {
            return CosmotownClient::forRegistrar($registrar)->getDomainInfo($fqdn);
        } catch (CosmotownException $e) {
            Log::warning('Cosmotown domaininfo failed during inventory sync', [
                'fqdn' => $fqdn,
                'error' => $e->getMessage(),
            ]);

            return $row;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $info
     */
    private function applyRemoteData(Domain $domain, Registrar $registrar, string $fqdn, array $row, array $info): bool
    {
        $updates = [
            'registrar' => $registrar->slug,
            'registrar_handle' => $fqdn,
        ];

        $expiry = $this->expirationFrom($info) ?? $this->expirationFrom($row);
        if ($expiry) {
            $updates['expires_at'] = $expiry->toDateString();
            $forSchedule = $domain->replicate();
            $forSchedule->expires_at = $expiry;
            $updates['next_invoice_date'] = $this->invoiceSchedule->domainNextInvoiceDate($forSchedule)->toDateString();
        }

        $created = $this->createdFrom($info) ?? $this->createdFrom($row);
        if ($created && $domain->registered_at === null) {
            $updates['registered_at'] = $created;
        }

        $nameservers = $this->nameserversFrom($info);
        if ($nameservers === []) {
            $nameservers = $this->nameserversFrom($row);
        }
        if ($nameservers !== []) {
            $updates['nameserver_1'] = $nameservers[0] ?? null;
            $updates['nameserver_2'] = $nameservers[1] ?? null;
            $updates['nameserver_3'] = $nameservers[2] ?? null;
            $updates['nameserver_4'] = $nameservers[3] ?? null;
        }

        $status = $this->statusFromExpiry($domain, $expiry);
        if ($status !== null) {
            $updates['status'] = $status;
        }

        $dirty = false;
        foreach ($updates as $key => $value) {
            $current = $domain->getAttribute($key);
            if ($current instanceof Carbon) {
                $current = $key === 'expires_at' || $key === 'next_invoice_date'
                    ? $current->toDateString()
                    : $current->toDateTimeString();
            }
            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $dirty = true;
                break;
            }
        }

        if (! $dirty) {
            return false;
        }

        $domain->update($updates);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expirationFrom(array $data): ?Carbon
    {
        $nested = is_array($data['domain'] ?? null) ? $data['domain'] : [];
        foreach (['expiration_date', 'expiry_date', 'expires'] as $key) {
            $raw = $data[$key] ?? ($nested[$key] ?? null);
            $parsed = CosmotownRegistrarDriver::parseExpiration(is_string($raw) ? $raw : null);
            if ($parsed) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createdFrom(array $data): ?Carbon
    {
        $nested = is_array($data['domain'] ?? null) ? $data['domain'] : [];
        foreach (['created', 'created_at', 'registration_date'] as $key) {
            $raw = $data[$key] ?? ($nested[$key] ?? null);
            $parsed = CosmotownRegistrarDriver::parseExpiration(is_string($raw) ? $raw : null);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function nameserversFrom(array $data): array
    {
        $raw = $data['nameservers'] ?? $data['name_servers'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $hosts = [];
        $seen = [];
        foreach ($raw as $ns) {
            $name = is_array($ns)
                ? trim((string) ($ns['name'] ?? $ns['hostname'] ?? $ns['ns'] ?? ''))
                : trim((string) $ns);
            $name = strtolower(rtrim($name, '.'));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $hosts[] = $name;
            if (count($hosts) === 4) {
                break;
            }
        }

        return $hosts;
    }

    private function statusFromExpiry(Domain $domain, ?Carbon $expiry): ?string
    {
        if ($expiry === null) {
            return null;
        }

        if (! in_array($domain->status, ['active', 'expired'], true)) {
            return null;
        }

        return $expiry->isFuture() || $expiry->isToday() ? 'active' : 'expired';
    }

    /**
     * @param  list<string>  $unmatched
     * @return array{
     *     success: bool,
     *     message: string,
     *     fetched: int,
     *     updated: int,
     *     unchanged: int,
     *     unmatched_count: int,
     *     unmatched: list<string>,
     *     errors: int
     * }
     */
    private function result(
        bool $success,
        string $message,
        int $fetched = 0,
        int $updated = 0,
        int $unchanged = 0,
        int $unmatchedCount = 0,
        array $unmatched = [],
        int $errors = 0,
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'fetched' => $fetched,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'unmatched_count' => $unmatchedCount,
            'unmatched' => $unmatched,
            'errors' => $errors,
        ];
    }
}
