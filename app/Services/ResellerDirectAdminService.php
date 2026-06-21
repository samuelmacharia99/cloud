<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ResellerDirectAdminService
{
    /**
     * Active DirectAdmin nodes resellers may connect to (API must be configured).
     *
     * @return Collection<int, Node>
     */
    public function availableNodes()
    {
        return Node::query()
            ->where('type', 'directadmin')
            ->where('is_active', true)
            ->whereNotNull('api_url')
            ->where('api_url', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'hostname', 'region', 'status', 'da_port']);
    }

    public function resolveConnectableNode(int $nodeId): ?Node
    {
        return $this->availableNodes()->firstWhere('id', $nodeId);
    }

    /**
     * Verify a DirectAdmin reseller username on a node before saving the binding.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     packages: list<array<string, mixed>>,
     *     hosted_user_count: ?int,
     *     disk_used_mb: ?float
     * }
     */
    public function verifyBinding(Node $node, string $username): array
    {
        $username = strtolower(trim($username));

        if (! preg_match('/^[a-z][a-z0-9_]*$/i', $username)) {
            return [
                'success' => false,
                'message' => 'Username must start with a letter and contain only letters, numbers, and underscores.',
                'packages' => [],
                'hosted_user_count' => null,
                'disk_used_mb' => null,
            ];
        }

        $da = new DirectAdminService($node);

        if (! $da->isConfigured()) {
            return [
                'success' => false,
                'message' => 'This server is not ready for connections yet. Contact your provider.',
                'packages' => [],
                'hosted_user_count' => null,
                'disk_used_mb' => null,
            ];
        }

        $hostedUserCount = $da->countUsersOwnedByReseller($username);

        if ($hostedUserCount === null) {
            return [
                'success' => false,
                'message' => 'Could not verify the DirectAdmin reseller account on this server. Check the username and try again.',
                'packages' => [],
                'hosted_user_count' => null,
                'disk_used_mb' => null,
            ];
        }

        $packages = $da->getResellerPackages($username);
        $diskUsedMb = $da->sumDiskUsageMbForResellerUsers($username);

        return [
            'success' => true,
            'message' => $packages === []
                ? 'Connected. No hosting packages found yet — create packages in DirectAdmin to sell plans.'
                : 'DirectAdmin reseller account verified successfully.',
            'packages' => $packages,
            'hosted_user_count' => $hostedUserCount,
            'disk_used_mb' => $diskUsedMb,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function connect(User $reseller, int $nodeId, string $username): array
    {
        if (! $reseller->is_reseller) {
            return ['success' => false, 'message' => 'Only reseller accounts can connect to DirectAdmin.'];
        }

        $node = $this->resolveConnectableNode($nodeId);

        if (! $node) {
            return ['success' => false, 'message' => 'Select a valid DirectAdmin server.'];
        }

        $verification = $this->verifyBinding($node, $username);

        if (! $verification['success']) {
            return ['success' => false, 'message' => $verification['message']];
        }

        $normalizedUsername = strtolower(trim($username));
        $settings = $reseller->settings ?? [];
        $settings['directadmin_connected_at'] = now()->toIso8601String();

        $reseller->update([
            'directadmin_username' => $normalizedUsername,
            'reseller_node_id' => $node->id,
            'settings' => $settings,
        ]);

        Log::info('Reseller connected DirectAdmin account', [
            'reseller_id' => $reseller->id,
            'node_id' => $node->id,
            'directadmin_username' => $normalizedUsername,
            'package_count' => count($verification['packages']),
            'hosted_user_count' => $verification['hosted_user_count'],
        ]);

        return [
            'success' => true,
            'message' => $verification['message'],
        ];
    }

    public function disconnect(User $reseller): void
    {
        if (! $reseller->is_reseller) {
            return;
        }

        $settings = $reseller->settings ?? [];
        unset($settings['directadmin_connected_at']);

        $reseller->update([
            'directadmin_username' => null,
            'reseller_node_id' => null,
            'settings' => $settings,
        ]);

        Log::info('Reseller disconnected DirectAdmin account', [
            'reseller_id' => $reseller->id,
        ]);
    }

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
