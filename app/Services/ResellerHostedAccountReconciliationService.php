<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResellerHostedAccountReconciliationService
{
    public function __construct(
        private ResellerHostedAccountDirectoryService $directory,
        private ResellerHostedAccountLinkService $linkService,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private NotificationService $notifications,
    ) {}

    /**
     * @return array{
     *     resellers_checked: int,
     *     unlinked_total: int,
     *     package_drift: int,
     *     missing_on_da: int,
     *     status_drift: int,
     *     notifications_sent: int
     * }
     */
    public function runScheduledReconciliation(bool $notify = true): array
    {
        $summary = [
            'resellers_checked' => 0,
            'unlinked_total' => 0,
            'package_drift' => 0,
            'missing_on_da' => 0,
            'status_drift' => 0,
            'notifications_sent' => 0,
        ];

        foreach ($this->connectedResellers() as $reseller) {
            $result = $this->reconcileReseller($reseller, healPackageDrift: true);
            $summary['resellers_checked']++;
            $summary['unlinked_total'] += $result['unlinked_count'];
            $summary['package_drift'] += count($result['package_drift']);
            $summary['missing_on_da'] += count($result['missing_on_da']);
            $summary['status_drift'] += count($result['status_drift']);

            if ($notify && $result['unlinked_count'] > 0) {
                $previous = (int) Cache::get($this->unlinkedCountCacheKey($reseller), 0);
                if ($result['unlinked_count'] !== $previous || $result['unlinked_count'] > $previous) {
                    $this->notifications->notifyResellerUnlinkedDirectAdminAccounts(
                        $reseller,
                        $result['unlinked_count'],
                        $result['unlinked_usernames'],
                    );
                    $summary['notifications_sent']++;
                }
            }

            Cache::put($this->unlinkedCountCacheKey($reseller), $result['unlinked_count'], 86400);
        }

        Log::info('DirectAdmin hosted account reconciliation completed', $summary);

        return $summary;
    }

    /**
     * @return array{
     *     unlinked_count: int,
     *     unlinked_usernames: list<string>,
     *     package_drift: list<array{service_id: int, username: string, platform_package: ?string, da_package: ?string}>,
     *     missing_on_da: list<array{service_id: int, username: string}>,
     *     status_drift: list<array{service_id: int, username: string, platform_status: string, da_status: string}>
     * }
     */
    public function reconcileReseller(User $reseller, bool $healPackageDrift = false): array
    {
        $rows = $this->directoryRowsForReseller($reseller);

        $unlinked = $rows
            ->filter(fn (array $row) => ($row['link_status'] ?? '') === 'unlinked' && ($row['source'] ?? '') === 'directadmin')
            ->values();

        $packageDrift = [];
        $missingOnDa = [];
        $statusDrift = [];

        $da = $this->resellerDirectAdmin->directAdmin($reseller);
        $daUsernames = $da
            ? array_map('strtolower', $da->listUsersOwnedByReseller((string) $reseller->directadmin_username) ?? [])
            : [];

        foreach ($rows as $row) {
            if (($row['link_status'] ?? '') !== 'linked' || ! ($row['service'] instanceof Service)) {
                continue;
            }

            /** @var Service $service */
            $service = $row['service'];
            $username = strtolower((string) ($row['da_username'] ?? ''));

            if ($username === '') {
                continue;
            }

            if ($da && ! in_array($username, $daUsernames, true)) {
                $missingOnDa[] = [
                    'service_id' => $service->id,
                    'username' => $username,
                ];

                continue;
            }

            $platformPackage = $service->service_meta['package_name'] ?? $service->service_meta['package'] ?? null;
            $daPackage = $row['da_package'] ?? null;

            if ($daPackage && $platformPackage && strcasecmp((string) $platformPackage, (string) $daPackage) !== 0) {
                $packageDrift[] = [
                    'service_id' => $service->id,
                    'username' => $username,
                    'platform_package' => (string) $platformPackage,
                    'da_package' => (string) $daPackage,
                ];

                if ($healPackageDrift) {
                    $this->healPackageDrift($reseller, $service, (string) $daPackage);
                }
            }

            $platformStatus = $service->status instanceof ServiceStatus
                ? $service->status->value
                : (string) $service->status;
            $daStatus = ($row['da_status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';

            if ($platformStatus === 'active' && $daStatus === 'suspended') {
                $statusDrift[] = [
                    'service_id' => $service->id,
                    'username' => $username,
                    'platform_status' => $platformStatus,
                    'da_status' => $daStatus,
                ];
            } elseif ($platformStatus === 'suspended' && $daStatus === 'active') {
                $statusDrift[] = [
                    'service_id' => $service->id,
                    'username' => $username,
                    'platform_status' => $platformStatus,
                    'da_status' => $daStatus,
                ];
            }
        }

        return [
            'unlinked_count' => $unlinked->count(),
            'unlinked_usernames' => $unlinked->pluck('da_username')->filter()->values()->all(),
            'package_drift' => $packageDrift,
            'missing_on_da' => $missingOnDa,
            'status_drift' => $statusDrift,
        ];
    }

    private function healPackageDrift(User $reseller, Service $service, string $daPackage): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['package_name'] = $daPackage;
        $meta['package'] = $daPackage;
        $meta['package_drift_healed_at'] = now()->toIso8601String();

        $updates = ['service_meta' => $meta];

        $listing = $this->linkService->resolveListingForPackage($reseller, $daPackage);
        if ($listing && empty($meta['reseller_product_id'])) {
            $product = app(ResellerProvisionProductResolver::class)->resolve($listing);
            if ($product) {
                $updates['product_id'] = $product->id;
                $updates['service_meta'] = array_merge($meta, $listing->directAdminPackageMeta(), [
                    'reseller_product_id' => $listing->id,
                ]);
            }
        }

        $service->update($updates);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function directoryRowsForReseller(User $reseller): Collection
    {
        return $this->directory->buildRowsForReseller($reseller);
    }

    /**
     * @return Collection<int, User>
     */
    private function connectedResellers(): Collection
    {
        return User::query()
            ->where('is_reseller', true)
            ->whereNotNull('directadmin_username')
            ->whereNotNull('directadmin_login_key')
            ->whereNotNull('reseller_node_id')
            ->get()
            ->filter(fn (User $reseller) => $this->resellerDirectAdmin->hasDirectAdminBinding($reseller))
            ->values();
    }

    private function unlinkedCountCacheKey(User $reseller): string
    {
        return 'reseller_da_unlinked_count:'.$reseller->id;
    }
}
