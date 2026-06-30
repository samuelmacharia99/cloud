<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Rules\ValidCountryCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResellerHostedAccountLinkService
{
    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerScopeService $scope,
        private ResellerProvisionProductResolver $productResolver,
        private UserCurrencyService $userCurrency,
    ) {}

    /**
     * @param  array{
     *     customer_id?: ?int,
     *     name?: ?string,
     *     email?: ?string,
     *     phone?: ?string,
     *     country?: ?string,
     *     reseller_product_id?: ?int,
     *     billing_cycle?: ?string,
     *     custom_price?: ?float,
     *     next_due_date?: ?string,
     * }  $options
     * @return array{customer: User, service: Service}
     */
    public function linkAccount(User $reseller, string $daUsername, array $options = []): array
    {
        $this->assertResellerCanLink($reseller);

        $daUsername = strtolower(trim($daUsername));
        $entry = $this->resolveOwnedAccountEntry($reseller, $daUsername);
        $node = $this->resellerDirectAdmin->resolveNode($reseller);

        if (! $node) {
            throw new \InvalidArgumentException('No DirectAdmin server is linked for this reseller.');
        }

        $this->assertUsernameAvailableOnPlatform($daUsername, $reseller);

        $listing = $this->resolveListing($reseller, $options['reseller_product_id'] ?? null, $entry['package'] ?? null);
        $product = $listing
            ? ($this->productResolver->resolve($listing) ?? $this->productResolver->shellDirectAdminProduct())
            : $this->productResolver->shellDirectAdminProduct();

        return DB::transaction(function () use ($reseller, $daUsername, $entry, $node, $listing, $product, $options) {
            $customer = $this->resolveCustomer($reseller, $options, $entry);

            $meta = [
                'username' => $daUsername,
                'domain' => $entry['domain'] ?? null,
                'package_name' => $entry['package'] ?? null,
                'package' => $entry['package'] ?? null,
                'node_id' => $node->id,
                'node_name' => $node->name,
                'directadmin_reseller' => $reseller->directadmin_username,
                'linked_from_directadmin_at' => now()->toIso8601String(),
                'link_existing' => true,
                'imported_from_directadmin' => true,
            ];

            if ($listing) {
                $meta = array_merge($meta, $listing->directAdminPackageMeta(), [
                    'reseller_product_id' => $listing->id,
                ]);
            }

            $billingCycle = $options['billing_cycle'] ?? 'annual';
            $status = ! empty($entry['suspended']) ? ServiceStatus::Suspended : ServiceStatus::Active;

            $service = Service::create([
                'user_id' => $customer->id,
                'reseller_id' => $reseller->id,
                'product_id' => $product->id,
                'node_id' => $node->id,
                'name' => $listing?->name ?? ($entry['domain'] ?? $daUsername),
                'status' => $status,
                'billing_cycle' => $billingCycle,
                'custom_price' => $options['custom_price'] ?? null,
                'next_due_date' => ! empty($options['next_due_date'])
                    ? Carbon::parse($options['next_due_date'])
                    : now()->addYear(),
                'commenced_at' => now(),
                'provisioning_driver_key' => 'directadmin',
                'service_meta' => $meta,
            ]);

            $updates = [
                'external_reference' => Service::resolveExternalReferenceForAssignment($daUsername, $service->id),
            ];

            if ($listing && empty($options['custom_price'])) {
                $updates['custom_price'] = $this->defaultPriceForCycle($listing, $billingCycle);
            }

            $service->update($updates);

            $this->forgetDirectoryCache($reseller);

            return [
                'customer' => $customer->fresh(),
                'service' => $service->fresh(['product', 'user']),
            ];
        });
    }

    /**
     * @param  array{
     *     reseller_product_id: int,
     *     billing_cycle?: ?string,
     *     custom_price?: ?float,
     *     next_due_date?: ?string,
     * }  $options
     */
    public function connectBilling(User $reseller, Service $service, array $options): Service
    {
        if (! $this->scope->ownsCustomer($reseller, $service->user) && (int) $service->reseller_id !== (int) $reseller->id) {
            abort(403);
        }

        $listing = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->findOrFail($options['reseller_product_id']);

        $product = $this->productResolver->resolve($listing)
            ?? throw new \InvalidArgumentException('Selected catalog item cannot be used for billing.');

        $billingCycle = $options['billing_cycle'] ?? $service->billing_cycle ?? 'annual';
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        $service->update([
            'product_id' => $product->id,
            'billing_cycle' => $billingCycle,
            'custom_price' => $options['custom_price'] ?? $this->defaultPriceForCycle($listing, $billingCycle),
            'next_due_date' => ! empty($options['next_due_date'])
                ? Carbon::parse($options['next_due_date'])
                : ($service->next_due_date ?? now()->addYear()),
            'service_meta' => array_merge($meta, $listing->directAdminPackageMeta(), [
                'reseller_product_id' => $listing->id,
                'billing_connected_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->forgetDirectoryCache($reseller);

        return $service->fresh(['product', 'user']);
    }

    /**
     * @param  list<string>  $usernames
     * @param  array<string, mixed>  $defaults
     * @return array{linked: int, failed: list<array{username: string, error: string}>}
     */
    public function bulkLink(User $reseller, array $usernames, array $defaults = []): array
    {
        $linked = 0;
        $failed = [];

        foreach ($usernames as $username) {
            $username = strtolower(trim((string) $username));
            if ($username === '') {
                continue;
            }

            try {
                $this->linkAccount($reseller, $username, $defaults);
                $linked++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'username' => $username,
                    'error' => $this->formatLinkError($e),
                ];
            }
        }

        return compact('linked', 'failed');
    }

    public function resolveListingForPackage(User $reseller, ?string $packageName): ?ResellerProduct
    {
        return $this->resolveListing($reseller, null, $packageName);
    }

    /**
     * @return array{username: string, domain: ?string, package: ?string, email: ?string, name: ?string, suspended: bool}
     */
    private function resolveOwnedAccountEntry(User $reseller, string $daUsername): array
    {
        $da = $this->resellerDirectAdmin->directAdmin($reseller);
        if (! $da) {
            throw new \InvalidArgumentException('DirectAdmin API is not available for this reseller.');
        }

        $owned = $da->listUsersOwnedByReseller((string) $reseller->directadmin_username) ?? [];
        $owned = array_map(fn ($u) => strtolower(trim((string) $u)), $owned);

        if (! in_array($daUsername, $owned, true)) {
            throw new \InvalidArgumentException("DirectAdmin account \"{$daUsername}\" is not on this reseller account.");
        }

        $entry = $da->getAccountDirectoryEntry($daUsername);
        if (! $entry) {
            throw new \InvalidArgumentException("Could not read DirectAdmin account \"{$daUsername}\".");
        }

        return $entry;
    }

    private function assertResellerCanLink(User $reseller): void
    {
        if (! $reseller->is_reseller) {
            throw new \InvalidArgumentException('Only reseller accounts can link DirectAdmin users.');
        }

        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            throw new \InvalidArgumentException('Connect your DirectAdmin account before linking hosted users.');
        }

        if ($reseller->isAtUserLimit()) {
            throw new \InvalidArgumentException('You have reached your hosted user limit. Upgrade your package first.');
        }
    }

    private function assertUsernameAvailableOnPlatform(string $daUsername, User $reseller): void
    {
        $existing = Service::query()
            ->where(function ($query) use ($daUsername) {
                $query->where('external_reference', $daUsername)
                    ->orWhere('service_meta->username', $daUsername);
            })
            ->where(function ($query) use ($reseller) {
                $query->where('reseller_id', $reseller->id)
                    ->orWhereHas('user', fn ($user) => $user->where('reseller_id', $reseller->id));
            })
            ->first();

        if ($existing && ! $existing->status->isTerminal()) {
            throw new \InvalidArgumentException("DirectAdmin user \"{$daUsername}\" is already linked to a platform service.");
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $entry
     */
    private function resolveCustomer(User $reseller, array $options, array $entry): User
    {
        if (! empty($options['customer_id'])) {
            $customer = User::query()->findOrFail((int) $options['customer_id']);
            if ($customer->reseller_id !== $reseller->id) {
                throw new \InvalidArgumentException('Selected customer does not belong to this reseller.');
            }

            return $customer;
        }

        $email = $options['email'] ?? $entry['email'] ?? null;
        if (! filled($email)) {
            throw ValidationException::withMessages([
                'email' => 'Email is required to create a platform customer for this DirectAdmin account.',
            ]);
        }

        $validator = Validator::make([
            'name' => $options['name'] ?? $entry['name'] ?? $entry['domain'] ?? 'Hosting customer',
            'email' => $email,
            'phone' => $options['phone'] ?? null,
            'country' => $options['country'] ?? 'KE',
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
            'country' => ['required', 'string', 'size:2', new ValidCountryCode],
        ]);

        $validator->validate();

        $customer = User::create([
            'name' => $validator->validated()['name'],
            'email' => $validator->validated()['email'],
            'phone' => $validator->validated()['phone'] ?? null,
            'country' => $validator->validated()['country'],
            'password' => Str::password(16),
            'reseller_id' => $reseller->id,
            'is_reseller' => false,
            'status' => 'active',
        ]);

        $this->userCurrency->syncFromCountry($customer, true);

        return $customer;
    }

    private function resolveListing(User $reseller, ?int $listingId, ?string $packageName): ?ResellerProduct
    {
        if ($listingId) {
            return ResellerProduct::query()
                ->where('reseller_id', $reseller->id)
                ->where('is_active', true)
                ->findOrFail($listingId);
        }

        if (! filled($packageName)) {
            return null;
        }

        $key = strtolower(trim($packageName));

        return ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->get()
            ->first(function (ResellerProduct $listing) use ($key) {
                return strtolower(trim((string) $listing->direct_admin_package_name)) === $key
                    || strtolower(trim((string) $listing->name)) === $key;
            });
    }

    private function defaultPriceForCycle(ResellerProduct $listing, string $cycle): float
    {
        return match ($cycle) {
            'monthly' => (float) ($listing->monthly_price ?? 0),
            'quarterly' => (float) (($listing->monthly_price ?? 0) * 3),
            'semi-annual' => (float) (($listing->monthly_price ?? 0) * 6),
            default => (float) ($listing->yearly_price ?? 0),
        };
    }

    private function forgetDirectoryCache(User $reseller): void
    {
        Cache::forget('reseller_hosted_directory:'.$reseller->id);
    }

    private function formatLinkError(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return $e->getMessage();
    }
}
