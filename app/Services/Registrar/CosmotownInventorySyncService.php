<?php

namespace App\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\DomainInputParser;
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
     * Live Cosmotown names that have no Admin → Domains row yet.
     *
     * @return list<array{fqdn: string, expires_at: ?string, locked: ?bool, whois_privacy: ?bool}>
     */
    public function unmatchedAtRegistrar(?Registrar $registrar = null): array
    {
        $registrar ??= $this->activeCosmotownRegistrar();
        if (! $registrar) {
            throw new \InvalidArgumentException('No active Cosmotown registrar is configured.');
        }

        $remote = CosmotownClient::forRegistrar($registrar)->listAllDomains();
        $local = $this->localByFqdn();
        $unmatched = [];

        foreach ($remote as $row) {
            $fqdn = $this->fqdnFromRow($row);
            if ($fqdn === '' || $local->has($fqdn)) {
                continue;
            }

            $expiry = $this->expirationFrom($row);

            $unmatched[] = [
                'fqdn' => $fqdn,
                'expires_at' => $expiry?->toDateString(),
                'locked' => $this->boolFrom($row, ['locked', 'lock_domain']),
                'whois_privacy' => $this->boolFrom($row, ['whois_privacy', 'enable_private_whois', 'private_whois']),
            ];
        }

        usort($unmatched, fn (array $a, array $b) => strcmp($a['fqdn'], $b['fqdn']));

        return $unmatched;
    }

    /**
     * Attach a Cosmotown-only name to an existing customer. Does not create an invoice or order.
     */
    public function importToCustomer(string $fqdn, User $owner, User $admin, bool $confirmedNoInvoice): Domain
    {
        if (! $confirmedNoInvoice) {
            throw new \InvalidArgumentException(
                'Confirm that this domain is already at Cosmotown and should appear on the customer account without creating an invoice.'
            );
        }

        if ($owner->is_admin) {
            throw new \InvalidArgumentException('Import onto a customer or reseller account, not an admin user.');
        }

        $registrar = $this->activeCosmotownRegistrar();
        if (! $registrar) {
            throw new \InvalidArgumentException('No active Cosmotown registrar is configured.');
        }

        $fqdn = strtolower(trim($fqdn));
        $extensions = DomainExtension::query()->pluck('extension')->all();
        $parsed = app(DomainInputParser::class)->parse($fqdn, null, $extensions);
        if ($parsed === null) {
            throw new \InvalidArgumentException(
                'That name does not match a TLD in Admin → Domain pricing. Add the extension first, then import.'
            );
        }

        $existing = Domain::query()
            ->where('name', $parsed['name'])
            ->where('extension', $parsed['extension'])
            ->first();
        if ($existing) {
            throw new \InvalidArgumentException(
                $fqdn.' is already on account #'.$existing->user_id.' (domain #'.$existing->id.').'
            );
        }

        $client = CosmotownClient::forRegistrar($registrar);

        try {
            $info = $client->getDomainInfo($fqdn);
        } catch (CosmotownException $e) {
            throw new \InvalidArgumentException(
                'Cosmotown does not list '.$fqdn.' on this reseller account. '.$e->getMessage()
            );
        }

        $expiry = $this->expirationFrom($info);
        $created = $this->createdFrom($info);
        $nameservers = $this->nameserversFrom($info);

        $domain = Domain::create([
            'user_id' => $owner->id,
            'reseller_id' => $owner->is_reseller ? $owner->id : $owner->reseller_id,
            'name' => $parsed['name'],
            'extension' => $parsed['extension'],
            'type' => 'registration',
            'status' => ($expiry && $expiry->isPast()) ? 'expired' : 'active',
            'registrar' => $registrar->slug,
            'registrar_handle' => $fqdn,
            'registered_at' => $created,
            'expires_at' => $expiry?->toDateString(),
            'auto_renew' => false,
            'nameserver_1' => $nameservers[0] ?? null,
            'nameserver_2' => $nameservers[1] ?? null,
            'nameserver_3' => $nameservers[2] ?? null,
            'nameserver_4' => $nameservers[3] ?? null,
            'registry_locked' => $this->boolFrom($info, ['locked', 'lock_domain']) ?? false,
            'whois_privacy' => $this->boolFrom($info, ['whois_privacy', 'enable_private_whois', 'private_whois']) ?? false,
        ]);

        if ($expiry) {
            $domain->update([
                'next_invoice_date' => $this->invoiceSchedule->domainNextInvoiceDate($domain->fresh())->toDateString(),
            ]);
        }

        AdminActivityService::log(
            'cosmotown_inventory_import',
            'Imported '.$fqdn.' from Cosmotown onto '.$owner->email.' without creating an invoice.',
            $domain,
            [
                'fqdn' => $fqdn,
                'owner_user_id' => $owner->id,
                'expires_at' => $expiry?->toDateString(),
            ],
        );

        return $domain->fresh();
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

        $locked = $this->boolFrom($info) ?? $this->boolFrom($row, ['locked', 'lock_domain']);
        if ($locked !== null) {
            $updates['registry_locked'] = $locked;
        }
        $privacy = $this->boolFrom($info, ['whois_privacy', 'enable_private_whois', 'private_whois'])
            ?? $this->boolFrom($row, ['whois_privacy', 'enable_private_whois', 'private_whois']);
        if ($privacy !== null) {
            $updates['whois_privacy'] = $privacy;
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
     * @param  list<string>  $keys
     */
    private function boolFrom(array $data, array $keys = ['locked', 'lock_domain']): ?bool
    {
        $bags = [$data];
        if (isset($data['domain']) && is_array($data['domain'])) {
            $bags[] = $data['domain'];
        }

        foreach ($bags as $bag) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $bag)) {
                    continue;
                }
                $value = $bag[$key];
                if (is_bool($value)) {
                    return $value;
                }
                if (is_numeric($value)) {
                    return (bool) $value;
                }
                if (is_string($value)) {
                    $normalized = strtolower(trim($value));
                    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                        return true;
                    }
                    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                        return false;
                    }
                }
            }
        }

        return null;
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
