<?php

namespace App\Services;

use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ResellerHostedAccountDirectoryService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerScopeService $scope,
    ) {}

    /**
     * @return array{
     *     rows: LengthAwarePaginator<int, array<string, mixed>>,
     *     stats: array<string, int|string|bool|null>,
     *     uses_directadmin: bool
     * }
     */
    public function paginatedForReseller(User $reseller, Request $request): array
    {
        $usesDirectAdmin = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);

        if (! $usesDirectAdmin) {
            return $this->paginatedPortalCustomersOnly($reseller, $request);
        }

        $rows = $this->cachedRowsForReseller($reseller, $request->boolean('refresh'));
        $filtered = $this->applyFilters($rows, $request, false);
        $stats = $this->statsFromRows($rows, true);

        return [
            'rows' => $this->paginateCollection($filtered, $request),
            'stats' => $stats,
            'uses_directadmin' => true,
        ];
    }

    /**
     * @return array{
     *     rows: LengthAwarePaginator<int, array<string, mixed>>,
     *     stats: array<string, int|string|bool|null>,
     *     uses_directadmin: bool
     * }
     */
    public function paginatedForAdmin(Request $request): array
    {
        $resellerId = $request->filled('reseller_id') ? (int) $request->reseller_id : null;
        $connectedResellers = $this->connectedResellers($resellerId);
        $usesDirectAdmin = $connectedResellers->isNotEmpty();

        $portalQuery = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->with('reseller:id,name,email')
            ->withCount('services', 'invoices')
            ->latest();

        if ($resellerId) {
            $portalQuery->where('reseller_id', $resellerId);
        }

        if ($usesDirectAdmin) {
            $rows = $this->buildAdminRows($connectedResellers, $portalQuery->get(), $request->boolean('refresh'));
            $filtered = $this->applyFilters($rows, $request, true);
            $stats = $this->statsFromRows($rows, true);

            return [
                'rows' => $this->paginateCollection($filtered, $request),
                'stats' => $stats,
                'uses_directadmin' => true,
            ];
        }

        $this->applyPortalQueryFilters($portalQuery, $request, true);

        return [
            'rows' => $portalQuery->paginate(15)->withQueryString(),
            'stats' => [
                'total' => (clone $portalQuery)->count(),
                'linked' => (clone $portalQuery)->count(),
                'unlinked' => 0,
                'directadmin_total' => 0,
                'portal_only' => (clone $portalQuery)->count(),
            ],
            'uses_directadmin' => false,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buildRowsForReseller(User $reseller, ?Collection $portalCustomers = null): Collection
    {
        if (! $this->resellerDirectAdmin->hasDirectAdminBinding($reseller)) {
            return collect();
        }

        $portalCustomers ??= $this->scope->managedCustomersQuery($reseller)
            ->withCount('services', 'invoices')
            ->get();

        return $this->buildResellerRows($reseller, $portalCustomers);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cachedRowsForReseller(User $reseller, bool $refresh): Collection
    {
        $cacheKey = 'reseller_hosted_directory:'.$reseller->id;

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($reseller) {
            $portalCustomers = $this->scope->managedCustomersQuery($reseller)
                ->withCount('services', 'invoices')
                ->get();

            return $this->buildResellerRows($reseller, $portalCustomers);
        });
    }

    /**
     * @param  Collection<int, User>  $portalCustomers
     * @return Collection<int, array<string, mixed>>
     */
    private function buildResellerRows(User $reseller, Collection $portalCustomers): Collection
    {
        $da = $this->resellerDirectAdmin->directAdmin($reseller);
        $daUsernames = $da?->listUsersOwnedByReseller((string) $reseller->directadmin_username) ?? [];
        $servicesByUsername = $this->servicesByHostingUsername($reseller);
        $catalogByPackage = $this->catalogByPackageName($reseller);
        $seenUserIds = [];

        $rows = collect();

        foreach ($daUsernames as $username) {
            $normalized = strtolower(trim($username));
            $entry = $da?->getAccountDirectoryEntry($normalized);
            $service = $servicesByUsername->get($normalized);
            $user = $service?->user;
            if ($user) {
                $seenUserIds[$user->id] = true;
            }

            $rows->push($this->makeRow(
                $reseller,
                $entry,
                $normalized,
                $user,
                $service,
                $catalogByPackage,
            ));
        }

        foreach ($portalCustomers as $customer) {
            if (isset($seenUserIds[$customer->id])) {
                continue;
            }

            $rows->push($this->makePortalOnlyRow($reseller, $customer));
        }

        return $this->sortRows($rows);
    }

    /**
     * @param  Collection<int, User>  $connectedResellers
     * @param  Collection<int, User>  $portalCustomers
     * @return Collection<int, array<string, mixed>>
     */
    private function buildAdminRows(Collection $connectedResellers, Collection $portalCustomers, bool $refresh): Collection
    {
        $rows = collect();
        $seenUserIds = [];

        foreach ($connectedResellers as $reseller) {
            $resellerRows = $this->cachedRowsForReseller($reseller, $refresh);
            foreach ($resellerRows as $row) {
                if (! empty($row['user']) && $row['user'] instanceof User) {
                    $seenUserIds[$row['user']->id] = true;
                }
                $rows->push($row);
            }
        }

        foreach ($portalCustomers as $customer) {
            if (isset($seenUserIds[$customer->id])) {
                continue;
            }

            $owner = $customer->reseller;
            if ($owner instanceof User && $this->resellerDirectAdmin->hasDirectAdminBinding($owner)) {
                continue;
            }

            $rows->push($this->makePortalOnlyRow($owner, $customer));
        }

        return $this->sortRows($rows);
    }

    /**
     * @param  array<string, ResellerProduct>  $catalogByPackage
     * @return array<string, mixed>
     */
    private function makeRow(
        ?User $reseller,
        ?array $daEntry,
        string $daUsername,
        ?User $user,
        ?Service $service,
        array $catalogByPackage,
    ): array {
        $daPackage = $daEntry['package'] ?? null;
        $matchedListing = $this->resolveMatchedListing($service, $daPackage, $catalogByPackage);
        $billingStatus = $this->resolveBillingStatus($service, $matchedListing);
        $isLinked = $user !== null && $service !== null;

        $displayName = $user?->name
            ?? ($daEntry['name'] ?? null)
            ?? $daUsername;
        $displayEmail = $user?->email ?? ($daEntry['email'] ?? null);

        return [
            'row_key' => 'da:'.$daUsername.':'.($reseller?->id ?? 0),
            'source' => 'directadmin',
            'link_status' => $isLinked ? 'linked' : 'unlinked',
            'billing_status' => $billingStatus,
            'da_username' => $daUsername,
            'da_domain' => $daEntry['domain'] ?? null,
            'da_package' => $daPackage,
            'da_status' => isset($daEntry['suspended']) && $daEntry['suspended'] ? 'suspended' : 'active',
            'user' => $user,
            'service' => $service,
            'reseller' => $reseller,
            'matched_listing' => $matchedListing,
            'display_name' => $displayName,
            'display_email' => $displayEmail,
            'services_count' => $user?->services_count ?? ($service ? 1 : 0),
            'invoices_count' => $user?->invoices_count ?? 0,
            'portal_status' => $user?->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makePortalOnlyRow(?User $reseller, User $customer): array
    {
        return [
            'row_key' => 'user:'.$customer->id,
            'source' => 'portal',
            'link_status' => 'unlinked',
            'billing_status' => 'needs_package',
            'da_username' => null,
            'da_domain' => null,
            'da_package' => null,
            'da_status' => null,
            'user' => $customer,
            'service' => null,
            'reseller' => $reseller,
            'matched_listing' => null,
            'display_name' => $customer->name,
            'display_email' => $customer->email,
            'services_count' => (int) ($customer->services_count ?? 0),
            'invoices_count' => (int) ($customer->invoices_count ?? 0),
            'portal_status' => $customer->status,
        ];
    }

    /**
     * @param  array<string, ResellerProduct>  $catalogByPackage
     */
    private function resolveMatchedListing(?Service $service, ?string $daPackage, array $catalogByPackage): ?ResellerProduct
    {
        if ($service) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $listingId = (int) ($meta['reseller_product_id'] ?? 0);
            if ($listingId > 0) {
                $listing = ResellerProduct::query()->find($listingId);
                if ($listing) {
                    return $listing;
                }
            }
        }

        if (! filled($daPackage)) {
            return null;
        }

        $key = strtolower(trim((string) $daPackage));

        return $catalogByPackage[$key] ?? null;
    }

    private function resolveBillingStatus(?Service $service, ?ResellerProduct $matchedListing): string
    {
        if ($service) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            if (! empty($meta['reseller_product_id'])) {
                return 'ready';
            }
        }

        return $matchedListing ? 'package_detected' : 'needs_package';
    }

    /**
     * @return Collection<string, Service>
     */
    private function servicesByHostingUsername(User $reseller): Collection
    {
        return $this->scope->managedServicesQuery($reseller)
            ->with(['user' => fn ($q) => $q->withCount('services', 'invoices')])
            ->get()
            ->mapWithKeys(function (Service $service) {
                $username = $this->hostingUsername($service);

                return $username ? [$username => $service] : [];
            });
    }

    /**
     * @return array<string, ResellerProduct>
     */
    private function catalogByPackageName(User $reseller): array
    {
        $map = [];

        ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->get()
            ->each(function (ResellerProduct $listing) use (&$map) {
                if (filled($listing->direct_admin_package_name)) {
                    $map[strtolower(trim((string) $listing->direct_admin_package_name))] = $listing;
                }

                $map[strtolower(trim((string) $listing->name))] = $listing;
            });

        return $map;
    }

    private function hostingUsername(Service $service): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $username = $meta['username'] ?? $service->external_reference ?? null;

        return filled($username) ? strtolower(trim((string) $username)) : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, Request $request, bool $forAdmin): Collection
    {
        $filtered = $rows;

        if ($request->filled('search')) {
            $needle = strtolower(trim((string) $request->search));
            $filtered = $filtered->filter(function (array $row) use ($needle) {
                $haystack = strtolower(implode(' ', array_filter([
                    $row['display_name'] ?? '',
                    $row['display_email'] ?? '',
                    $row['da_username'] ?? '',
                    $row['da_domain'] ?? '',
                    $row['user']?->company ?? '',
                ])));

                return str_contains($haystack, $needle);
            });
        }

        if ($request->filled('link') && $request->link !== 'all') {
            $filtered = $filtered->where('link_status', $request->link);
        }

        if ($request->filled('billing') && $request->billing !== 'all') {
            $filtered = $filtered->where('billing_status', $request->billing);
        }

        if ($forAdmin) {
            if ($request->filled('owner') && $request->owner !== 'all') {
                if ($request->owner === 'platform') {
                    $filtered = $filtered->filter(fn (array $row) => empty($row['reseller']));
                } elseif ($request->owner === 'reseller') {
                    $filtered = $filtered->filter(fn (array $row) => ! empty($row['reseller']));
                }
            }

            if ($request->filled('type')) {
                if ($request->type === 'company') {
                    $filtered = $filtered->filter(fn (array $row) => filled($row['user']?->company));
                } elseif ($request->type === 'individual') {
                    $filtered = $filtered->filter(fn (array $row) => blank($row['user']?->company));
                }
            }
        }

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'unverified') {
                $filtered = $filtered->filter(fn (array $row) => $row['user'] && ! $row['user']->email_verified_at);
            } elseif ($request->status === 'suspended') {
                $filtered = $filtered->filter(fn (array $row) => ($row['portal_status'] ?? null) === 'suspended'
                    || ($row['da_status'] ?? null) === 'suspended');
            } else {
                $filtered = $filtered->filter(function (array $row) use ($request) {
                    if ($row['user']) {
                        return ($row['portal_status'] ?? null) === $request->status;
                    }

                    return ($row['da_status'] ?? 'active') === $request->status;
                });
            }
        }

        return $filtered->values();
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyPortalQueryFilters($query, Request $request, bool $forAdmin): void
    {
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('company', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'unverified') {
                $query->whereNull('email_verified_at');
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($forAdmin && $request->filled('type')) {
            if ($request->type === 'company') {
                $query->whereNotNull('company')->where('company', '!=', '');
            } elseif ($request->type === 'individual') {
                $query->where(function ($q) {
                    $q->whereNull('company')->orWhere('company', '');
                });
            }
        }

        if ($forAdmin && $request->filled('owner') && $request->owner !== 'all') {
            if ($request->owner === 'platform') {
                $query->whereNull('reseller_id');
            } elseif ($request->owner === 'reseller') {
                $query->whereNotNull('reseller_id');
            }
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function statsFromRows(Collection $rows, bool $usesDirectAdmin): array
    {
        return [
            'total' => $rows->count(),
            'linked' => $rows->where('link_status', 'linked')->count(),
            'unlinked' => $rows->where('link_status', 'unlinked')->count(),
            'directadmin_total' => $usesDirectAdmin ? $rows->where('source', 'directadmin')->count() : 0,
            'portal_only' => $rows->where('source', 'portal')->count(),
            'billing_ready' => $rows->where('billing_status', 'ready')->count(),
            'package_detected' => $rows->where('billing_status', 'package_detected')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateCollection(Collection $items, Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * @return array{
     *     rows: LengthAwarePaginator,
     *     stats: array<string, int>,
     *     uses_directadmin: bool
     * }
     */
    private function paginatedPortalCustomersOnly(User $reseller, Request $request): array
    {
        $query = $this->scope->managedCustomersQuery($reseller)->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('company', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('link') && $request->link === 'linked') {
            $query->whereRaw('0 = 1');
        }

        $total = (clone $query)->count();

        return [
            'rows' => $query->withCount('services', 'invoices')->paginate(15)->withQueryString(),
            'stats' => [
                'total' => $total,
                'linked' => $total,
                'unlinked' => 0,
                'directadmin_total' => 0,
                'portal_only' => $total,
                'billing_ready' => 0,
                'package_detected' => 0,
            ],
            'uses_directadmin' => false,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function connectedResellers(?int $resellerId): Collection
    {
        $query = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('directadmin_username')
            ->whereNotNull('directadmin_login_key')
            ->whereNotNull('reseller_node_id');

        if ($resellerId) {
            $query->whereKey($resellerId);
        }

        return $query->get()->filter(fn (User $reseller) => $this->resellerDirectAdmin->hasDirectAdminBinding($reseller))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows): Collection
    {
        return $rows->sortBy(fn (array $row) => strtolower((string) ($row['display_name'] ?? '')))->values();
    }
}
