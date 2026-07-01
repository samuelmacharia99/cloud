<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResellerDirectAdminService
{
    private const METRICS_CACHE_SECONDS = 90;

    /** @var array<string, int|float|null> */
    private array $requestMetricCache = [];

    /**
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
        return Node::query()
            ->where('id', $nodeId)
            ->where('type', 'directadmin')
            ->where('is_active', true)
            ->whereNotNull('api_url')
            ->where('api_url', '!=', '')
            ->first();
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     packages: list<array<string, mixed>>,
     *     hosted_user_count: ?int,
     *     disk_used_mb: ?float
     * }
     */
    public function verifyBinding(Node $node, string $username, string $loginKey): array
    {
        $username = strtolower(trim($username));
        $loginKey = trim($loginKey);

        if (! preg_match('/^[a-z][a-z0-9_]*$/i', $username)) {
            return $this->verificationFailure('Username must start with a letter and contain only letters, numbers, and underscores.');
        }

        if ($loginKey === '') {
            return $this->verificationFailure('DirectAdmin login key is required.');
        }

        if (blank($node->api_url)) {
            return $this->verificationFailure('This server is not ready for connections yet. Contact your provider.');
        }

        $da = DirectAdminService::forResellerAccount($node, $username, $loginKey);

        if (! $da->isConfigured()) {
            return $this->verificationFailure('This server is not ready for connections yet. Contact your provider.');
        }

        $hostedUserCount = $da->countUsersOwnedByReseller($username);

        if ($hostedUserCount === null) {
            return $this->verificationFailure('Could not verify the DirectAdmin account. Check the username and login key.');
        }

        $packages = $da->getResellerPackages($username);
        $diskUsedMb = $da->sumDiskUsageMbForResellerUsers($username);

        return [
            'success' => true,
            'message' => $packages === []
                ? 'Verified. No hosting packages found yet — create packages in DirectAdmin before selling plans.'
                : 'DirectAdmin reseller account verified successfully.',
            'packages' => $packages,
            'hosted_user_count' => $hostedUserCount,
            'disk_used_mb' => $diskUsedMb,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function connect(User $reseller, int $nodeId, string $username, string $loginKey): array
    {
        if (! $reseller->is_reseller) {
            return ['success' => false, 'message' => 'Only reseller accounts can connect to DirectAdmin.'];
        }

        $node = $this->resolveConnectableNode($nodeId);

        if (! $node) {
            return ['success' => false, 'message' => 'Select a valid DirectAdmin server.'];
        }

        $verification = $this->verifyBinding($node, $username, $loginKey);

        if (! $verification['success']) {
            return ['success' => false, 'message' => $verification['message']];
        }

        $normalizedUsername = strtolower(trim($username));
        $settings = $reseller->settings ?? [];
        $settings['directadmin_connected_at'] = now()->toIso8601String();

        $reseller->update([
            'directadmin_username' => $normalizedUsername,
            'reseller_node_id' => $node->id,
            'directadmin_login_key' => trim($loginKey),
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
            'directadmin_login_key' => null,
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
            && filled($reseller->directadmin_login_key)
            && $this->resolveNode($reseller) !== null;
    }

    public function canAutoProvision(User $reseller): bool
    {
        return $this->hasDirectAdminBinding($reseller);
    }

    /**
     * @return array{success: bool, url?: string, message?: string}
     */
    public function createPanelLoginUrl(User $reseller): array
    {
        if (! filled($reseller->directadmin_username)) {
            return ['success' => false, 'message' => 'DirectAdmin username is not linked for this reseller.'];
        }

        $node = $this->resolveNode($reseller);
        if (! $node) {
            return ['success' => false, 'message' => 'No DirectAdmin server is linked for this reseller.'];
        }

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);

        if (! $api->isAvailable()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured on this server.'];
        }

        return $api->createOneTimeLoginUrl((string) $reseller->directadmin_username);
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

        if (! $node || ! filled($reseller->directadmin_username) || ! filled($reseller->directadmin_login_key)) {
            return null;
        }

        $service = DirectAdminService::forResellerAccount(
            $node,
            (string) $reseller->directadmin_username,
            (string) $reseller->directadmin_login_key,
        );

        return $service->isConfigured() ? $service : null;
    }

    public function adminDirectAdmin(?Node $node): ?DirectAdminService
    {
        if (! $node) {
            return null;
        }

        $service = new DirectAdminService($node);

        return $service->isConfigured() ? $service : null;
    }

    public function resolveResellerForService(Service $service): ?User
    {
        if ($service->reseller_id) {
            return User::query()->find($service->reseller_id);
        }

        $service->loadMissing('user');

        if ($service->user?->reseller_id) {
            return User::query()->find($service->user->reseller_id);
        }

        return null;
    }

    public function serviceUsesResellerDirectAuth(Service $service): bool
    {
        $reseller = $this->resolveResellerForService($service);

        return $reseller !== null && $this->canAutoProvision($reseller);
    }

    public function directAdminForService(Service $service): ?DirectAdminService
    {
        $reseller = $this->resolveResellerForService($service);

        if ($reseller && $this->canAutoProvision($reseller)) {
            return $this->directAdmin($reseller);
        }

        $service->loadMissing('node');
        $node = $service->node;

        return $this->adminDirectAdmin($node);
    }

    public function impersonationUsernameForService(Service $service): ?string
    {
        if ($this->serviceUsesResellerDirectAuth($service)) {
            return null;
        }

        $meta = $service->service_meta ?? [];
        $ownerReseller = $meta['directadmin_reseller'] ?? null;

        if (! $ownerReseller && $service->reseller_id) {
            $ownerReseller = User::query()
                ->whereKey($service->reseller_id)
                ->value('directadmin_username');
        }

        return filled($ownerReseller) ? (string) $ownerReseller : null;
    }

    /**
     * @return array{packages: list<array<string, mixed>>, error: ?string}
     */
    public function listAssignablePackages(User $reseller): array
    {
        if (! $this->hasDirectAdminBinding($reseller)) {
            return [
                'packages' => [],
                'error' => 'Your account is not linked to a DirectAdmin reseller. Ask your provider to link your DirectAdmin account from the admin reseller profile.',
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

        return $this->rememberMetric($reseller, 'hosted_user_count', function () use ($reseller) {
            $da = $this->directAdmin($reseller);

            if (! $da) {
                return null;
            }

            return $da->countUsersOwnedByReseller($reseller->directadmin_username);
        });
    }

    public function fetchHostedUserCountOnNode(User $reseller, Node $node): ?int
    {
        if (! filled($reseller->directadmin_username) || $node->type !== 'directadmin') {
            return null;
        }

        if ((int) $reseller->reseller_node_id === (int) $node->id && filled($reseller->directadmin_login_key)) {
            $da = $this->directAdmin($reseller);
        } else {
            $da = $this->adminDirectAdmin($node);
        }

        if (! $da) {
            return null;
        }

        return $da->countUsersOwnedByReseller($reseller->directadmin_username);
    }

    public function fetchTotalHostedDiskMb(User $reseller): ?float
    {
        if (! filled($reseller->directadmin_username)) {
            return null;
        }

        return $this->rememberMetric($reseller, 'disk_used_mb', function () use ($reseller) {
            $da = $this->directAdmin($reseller);

            if (! $da) {
                return null;
            }

            return $da->sumDiskUsageMbForResellerUsers($reseller->directadmin_username);
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function rememberMetric(User $reseller, string $metric, callable $callback): mixed
    {
        $memoryKey = "{$reseller->id}:{$metric}";
        if (array_key_exists($memoryKey, $this->requestMetricCache)) {
            return $this->requestMetricCache[$memoryKey];
        }

        $cacheKey = "reseller_da_metric:{$reseller->id}:{$metric}";

        /** @var T $value */
        $value = Cache::remember($cacheKey, self::METRICS_CACHE_SECONDS, $callback);
        $this->requestMetricCache[$memoryKey] = $value;

        return $value;
    }

    public function flushMetricsCache(User $reseller): void
    {
        foreach (['hosted_user_count', 'disk_used_mb'] as $metric) {
            Cache::forget("reseller_da_metric:{$reseller->id}:{$metric}");
            unset($this->requestMetricCache["{$reseller->id}:{$metric}"]);
        }
    }

    public function fetchTotalHostedDiskMbOnNode(User $reseller, Node $node): ?float
    {
        if (! filled($reseller->directadmin_username) || $node->type !== 'directadmin') {
            return null;
        }

        if ((int) $reseller->reseller_node_id === (int) $node->id && filled($reseller->directadmin_login_key)) {
            $da = $this->directAdmin($reseller);
        } else {
            $da = $this->adminDirectAdmin($node);
        }

        if (! $da) {
            return null;
        }

        return $da->sumDiskUsageMbForResellerUsers($reseller->directadmin_username);
    }

    public function suspendResellerAccount(User $reseller): bool
    {
        if (! filled($reseller->directadmin_username) || ! $this->resolveNode($reseller)) {
            return false;
        }

        $da = $this->adminDirectAdmin($this->resolveNode($reseller));
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
        if (! filled($reseller->directadmin_username) || ! $this->resolveNode($reseller)) {
            return false;
        }

        $da = $this->adminDirectAdmin($this->resolveNode($reseller));
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

    /**
     * @return array{
     *     success: false,
     *     message: string,
     *     packages: array{},
     *     hosted_user_count: null,
     *     disk_used_mb: null
     * }
     */
    private function verificationFailure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'packages' => [],
            'hosted_user_count' => null,
            'disk_used_mb' => null,
        ];
    }
}
