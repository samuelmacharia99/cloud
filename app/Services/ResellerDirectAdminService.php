<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Support\Facades\Log;

class ResellerDirectAdminService
{
    public function hasDirectAdminBinding(User $reseller): bool
    {
        return $reseller->is_reseller
            && filled($reseller->directadmin_username)
            && $this->resolveNode($reseller) !== null;
    }

    public function resolveNode(User $reseller): ?Node
    {
        if ($reseller->reseller_node_id) {
            $node = Node::query()
                ->where('id', $reseller->reseller_node_id)
                ->where('type', 'directadmin')
                ->where('is_active', true)
                ->first();

            if ($node) {
                return $node;
            }
        }

        $nodeId = Service::query()
            ->where('reseller_id', $reseller->id)
            ->whereNotNull('node_id')
            ->selectRaw('node_id, COUNT(*) as usage_count')
            ->groupBy('node_id')
            ->orderByDesc('usage_count')
            ->value('node_id');

        if (! $nodeId) {
            return null;
        }

        return Node::query()
            ->where('id', $nodeId)
            ->where('type', 'directadmin')
            ->where('is_active', true)
            ->first();
    }

    public function directAdmin(User $reseller): ?DirectAdminService
    {
        $node = $this->resolveNode($reseller);

        if (! $node) {
            return null;
        }

        $service = new DirectAdminService($node);

        return $service->isConfigured() ? $service : null;
    }

    /**
     * @return array{packages: list<array<string, mixed>>, error: ?string}
     */
    public function listAssignablePackages(User $reseller): array
    {
        if (! $this->hasDirectAdminBinding($reseller)) {
            return [
                'packages' => [],
                'error' => 'Your account is not linked to a DirectAdmin reseller. Ask your provider to set your DirectAdmin username and server.',
            ];
        }

        $da = $this->directAdmin($reseller);
        if (! $da) {
            return [
                'packages' => [],
                'error' => 'Could not connect to the DirectAdmin API for your server. Contact your provider.',
            ];
        }

        $packages = $da->getResellerPackages((string) $reseller->directadmin_username);

        if ($packages === []) {
            return [
                'packages' => [],
                'error' => 'No shared hosting packages were found on your DirectAdmin reseller account. Create packages in DirectAdmin first.',
            ];
        }

        usort($packages, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return ['packages' => $packages, 'error' => null];
    }

    public function packageNameIsValid(User $reseller, string $packageName): bool
    {
        if (! filled($packageName) || ! $this->hasDirectAdminBinding($reseller)) {
            return false;
        }

        $result = $this->listAssignablePackages($reseller);

        return collect($result['packages'])
            ->contains(fn (array $package) => ($package['name'] ?? '') === $packageName);
    }

    public function fetchHostedUserCount(User $reseller): ?int
    {
        if (! filled($reseller->directadmin_username)) {
            return null;
        }

        $da = $this->directAdmin($reseller);

        if (! $da) {
            return null;
        }

        return $da->countUsersOwnedByReseller($reseller->directadmin_username);
    }

    public function fetchHostedUserCountOnNode(User $reseller, Node $node): ?int
    {
        if (! filled($reseller->directadmin_username) || $node->type !== 'directadmin') {
            return null;
        }

        $da = new DirectAdminService($node);

        if (! $da->isConfigured()) {
            return null;
        }

        return $da->countUsersOwnedByReseller($reseller->directadmin_username);
    }

    /**
     * Total disk used (MB) by all end-user accounts on the reseller's DirectAdmin account.
     * Includes hosting accounts created directly in DirectAdmin, not only via this platform.
     */
    public function fetchTotalHostedDiskMb(User $reseller): ?float
    {
        if (! filled($reseller->directadmin_username)) {
            return null;
        }

        $da = $this->directAdmin($reseller);

        if (! $da) {
            return null;
        }

        return $da->sumDiskUsageMbForResellerUsers($reseller->directadmin_username);
    }

    public function fetchTotalHostedDiskMbOnNode(User $reseller, Node $node): ?float
    {
        if (! filled($reseller->directadmin_username) || $node->type !== 'directadmin') {
            return null;
        }

        $da = new DirectAdminService($node);

        if (! $da->isConfigured()) {
            return null;
        }

        return $da->sumDiskUsageMbForResellerUsers($reseller->directadmin_username);
    }

    public function suspendResellerAccount(User $reseller): bool
    {
        if (! $this->hasDirectAdminBinding($reseller)) {
            return false;
        }

        $da = $this->directAdmin($reseller);
        if (! $da) {
            return false;
        }

        $ok = $da->suspendUserByUsername($reseller->directadmin_username);

        if ($ok) {
            Log::info('DirectAdmin reseller account suspended', [
                'reseller_id' => $reseller->id,
                'username' => $reseller->directadmin_username,
            ]);
        }

        return $ok;
    }

    public function unsuspendResellerAccount(User $reseller): bool
    {
        if (! $this->hasDirectAdminBinding($reseller)) {
            return false;
        }

        $da = $this->directAdmin($reseller);
        if (! $da) {
            return false;
        }

        $ok = $da->unsuspendUserByUsername($reseller->directadmin_username);

        if ($ok) {
            Log::info('DirectAdmin reseller account unsuspended', [
                'reseller_id' => $reseller->id,
                'username' => $reseller->directadmin_username,
            ]);
        }

        return $ok;
    }
}
