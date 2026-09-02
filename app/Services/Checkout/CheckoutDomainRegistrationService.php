<?php

namespace App\Services\Checkout;

use App\Enums\InvoiceStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CheckoutDomainRegistrationService
{
    /**
     * Statuses the availability checker treats as free to register again.
     *
     * @var list<string>
     */
    public const RECLAIMABLE_STATUSES = ['cancelled', 'terminated'];

    /**
     * Create a pending registration row, or reuse the unique (name, extension) row
     * when it is safe. Never inserts a second row — that trips domains_name_extension_unique.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrReuse(
        User $user,
        string $name,
        string $extension,
        array $attributes = [],
        ?int $currentInvoiceId = null,
    ): Domain {
        $name = strtolower(trim($name));
        $extension = str_starts_with($extension, '.') ? $extension : '.'.$extension;
        $fqdn = $name.$extension;

        try {
            return $this->resolve($user, $name, $extension, $fqdn, $attributes, $currentInvoiceId);
        } catch (UniqueConstraintViolationException) {
            return $this->resolve($user, $name, $extension, $fqdn, $attributes, $currentInvoiceId);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolve(
        User $user,
        string $name,
        string $extension,
        string $fqdn,
        array $attributes,
        ?int $currentInvoiceId,
    ): Domain {
        $existing = Domain::query()
            ->where('name', $name)
            ->where('extension', $extension)
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            return Domain::create(array_merge($attributes, [
                'user_id' => $user->id,
                'name' => $name,
                'extension' => $extension,
                'status' => $attributes['status'] ?? 'pending',
            ]));
        }

        if (in_array($existing->status, self::RECLAIMABLE_STATUSES, true)) {
            $existing->fill(array_merge($this->reclaimDefaults(), $attributes, [
                'user_id' => $user->id,
                'reseller_id' => $attributes['reseller_id'] ?? $user->reseller_id,
                'name' => $name,
                'extension' => $extension,
                'status' => $attributes['status'] ?? 'pending',
            ]));
            $existing->save();

            return $existing->fresh();
        }

        if ((int) $existing->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'domain' => "{$fqdn} is already registered on this platform.",
            ]);
        }

        if ($existing->status !== 'pending') {
            throw ValidationException::withMessages([
                'domain' => "{$fqdn} is already in your account. Open it under Domains instead of registering it again.",
            ]);
        }

        $openInvoice = $this->openInvoiceForDomain($existing, $user, $currentInvoiceId);
        if ($openInvoice) {
            throw ValidationException::withMessages([
                'domain' => "You already have an unpaid invoice for {$fqdn} ({$openInvoice->invoice_number}). Pay that invoice instead of placing a new order.",
            ]);
        }

        $existing->fill($attributes);
        $existing->save();

        return $existing->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function reclaimDefaults(): array
    {
        return [
            'type' => 'registration',
            'registrar' => null,
            'registrar_external_id' => null,
            'registrar_handle' => null,
            'registered_at' => null,
            'expires_at' => null,
            'next_invoice_date' => null,
            'cloudflare_zone_id' => null,
            'domain_order_id' => null,
            'transfer_status' => null,
            'epp_code' => null,
        ];
    }

    private function openInvoiceForDomain(Domain $domain, User $user, ?int $exceptInvoiceId): ?Invoice
    {
        $openStatuses = [
            InvoiceStatus::Unpaid,
            InvoiceStatus::Overdue,
            InvoiceStatus::Draft,
        ];

        $itemQuery = InvoiceItem::query()
            ->where('domain_id', $domain->id)
            ->whereHas('invoice', function ($query) use ($user, $openStatuses) {
                $query->where('user_id', $user->id)
                    ->whereIn('status', $openStatuses);
            });

        if ($exceptInvoiceId) {
            $itemQuery->where('invoice_id', '!=', $exceptInvoiceId);
        }

        $fromItem = $itemQuery->first()?->invoice;
        if ($fromItem) {
            return $fromItem;
        }

        $serviceQuery = Service::query()
            ->where('user_id', $user->id)
            ->where('service_meta->domain_id', $domain->id)
            ->whereNotNull('invoice_id')
            ->whereHas('invoice', function ($query) use ($openStatuses) {
                $query->whereIn('status', $openStatuses);
            });

        if ($exceptInvoiceId) {
            $serviceQuery->where('invoice_id', '!=', $exceptInvoiceId);
        }

        return $serviceQuery->first()?->invoice;
    }
}
