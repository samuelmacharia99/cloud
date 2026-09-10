<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Whether a reseller's customers are actually being hosted.
 *
 * The dashboard could tell a reseller that an invoice was unpaid and that a
 * domain was expiring, but not that a customer's application had been down
 * since Tuesday. Money was watched; hosting was not. This is the missing half.
 *
 * It is one rollup, computed once per dashboard render and handed to both the
 * action queue and the infrastructure panel, so neither pays for it twice.
 */
class ResellerHostingHealthService
{
    /**
     * Deployment states that mean a customer's application is not serving.
     *
     * `deploying` is deliberately absent. It is the normal middle of every
     * redeploy, and an alert that fires on every deploy is one a reseller
     * learns to scroll past.
     *
     * @var list<string>
     */
    private const DOWN_STATES = ['stopped', 'failed'];

    public function __construct(private ResellerScopeService $scope) {}

    /**
     * @return array{failed_services: int, suspended_services: int, containers_down: int, total_services: int}
     */
    public function snapshot(User $reseller): array
    {
        $statusCounts = $this->scope->managedServicesQuery($reseller)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'failed_services' => $this->countFor($statusCounts, ServiceStatus::Failed),
            'suspended_services' => $this->countFor($statusCounts, ServiceStatus::Suspended),
            'containers_down' => $this->containersDown($reseller),
            'total_services' => (int) $statusCounts->sum(),
        ];
    }

    /**
     * Applications the platform believes are live while their container is not.
     *
     * A suspended service with a stopped container is doing what it was told,
     * so only an active service counts as down here.
     */
    public function containersDown(User $reseller): int
    {
        $serviceIds = $this->scope->managedServicesQuery($reseller)
            ->where('status', ServiceStatus::Active)
            ->pluck('id');

        if ($serviceIds->isEmpty()) {
            return 0;
        }

        return ContainerDeployment::query()
            ->whereIn('service_id', $serviceIds)
            ->whereIn('status', self::DOWN_STATES)
            ->count();
    }

    /**
     * @param  Collection<string, int>  $counts
     */
    private function countFor($counts, ServiceStatus $status): int
    {
        return (int) ($counts[$status->value] ?? 0);
    }
}
