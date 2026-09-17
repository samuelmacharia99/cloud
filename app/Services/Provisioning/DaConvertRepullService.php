<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Pull a converted account from DirectAdmin again, from scratch. Everything
 * the first convert built on the container side goes: the primary's
 * container, its database volume and files, and every sibling site the
 * convert created for the account's extra domains. The service row, its
 * billing, its Mailcow email service, its domains and its backups stay. The
 * row is put back on DirectAdmin and queued like a first convert, so the
 * export, deploy, import, go-live and mail steps all run again against what
 * DirectAdmin holds today.
 */
class DaConvertRepullService
{
    public function __construct(
        private DaConvertProgress $progress,
        private DirectAdminToContainerMigrationService $migrator,
        private ContainerDeploymentService $deployments,
        private DaConvertRetryService $retry,
    ) {}

    /**
     * Whether a fresh pull makes sense, without touching any node.
     *
     * @return array{ok: bool, blockers: list<string>, siblings: int}
     */
    public function assess(Service $service): array
    {
        $service->loadMissing(['product', 'containerDeployment']);
        $convert = $this->progress->convertMeta($service);
        $blockers = [];

        if ($this->progress->isActive($convert) && ! $this->progress->looksStuck($convert, $service)) {
            $blockers[] = 'A convert is still running for this account.';
        }
        if ($service->provisioningDriver() !== 'container' && (string) ($convert['status'] ?? '') !== 'completed') {
            $blockers[] = 'This account has not been converted yet; queue it normally.';
        }
        $previous = $convert['previous'] ?? null;
        if (! is_array($previous) || empty($previous['product_id'])) {
            $blockers[] = 'The convert record has no DirectAdmin product to put the row back on.';
        }
        if (! $this->migrator->canRepullDirectAdminFiles($service)) {
            $blockers[] = $this->migrator->directAdminHomeKnownMissing($service)
                ? 'The DirectAdmin account was found removed on an earlier pull; there is nothing to pull from.'
                : 'The convert record does not name the DirectAdmin node and docroot to pull from.';
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'siblings' => $this->progress->siblingServices($service)->count(),
        ];
    }

    /**
     * Tear the container side down and put the row back on DirectAdmin,
     * ready to be queued. Throws when the pull cannot go ahead; nothing is
     * removed before every check has passed.
     *
     * @return array{removed_siblings: int, container: ?string}
     */
    public function wipeForRepull(Service $service, User $actor): array
    {
        $assessment = $this->assess($service);
        if (! $assessment['ok']) {
            throw new \RuntimeException(implode(' ', $assessment['blockers']));
        }
        if (! $this->migrator->directAdminHomePresent($service)) {
            throw new \RuntimeException('The DirectAdmin account no longer exists on its node, so there is nothing to pull from. This button will not be offered again.');
        }

        $siblings = $this->progress->siblingServices($service);
        $removed = 0;
        foreach ($siblings as $sibling) {
            $this->removeSibling($sibling);
            $removed++;
        }

        $containerName = $service->containerDeployment?->container_name;
        $this->tearDownContainer($service);

        $this->retry->restoreDirectAdminRow($service);
        $service->refresh();

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $convert = is_array($meta['da_convert'] ?? null) ? $meta['da_convert'] : [];
        $history = is_array($convert['repulls'] ?? null) ? $convert['repulls'] : [];
        $history[] = [
            'at' => now()->toIso8601String(),
            'by_user_id' => $actor->id,
            'container' => $containerName,
            'removed_siblings' => $removed,
            'previous_completed_at' => $convert['completed_at'] ?? null,
        ];
        $meta['da_convert'] = array_intersect_key($convert, array_flip(['previous', 'options', 'attempt', 'mode', 'target_product_id', 'target_product_name', 'reseller_product_id', 'reseller_product_name', 'renewal_due_date', 'stack', 'quiet', 'no_invoice', 'keep_reseller_price']))
            + ['repulls' => array_slice($history, -10), 'repulled_at' => now()->toIso8601String()];
        // Facts gathered from the old container are stale now.
        unset($meta['da_migration'], $meta['integrity_scan'], $meta['wordpress_installed_at'], $meta['wordpress_sso']);
        $service->update(['service_meta' => $meta, 'project_id' => $service->project_id]);

        Log::info('Converted account wiped for a fresh DirectAdmin pull', [
            'service_id' => $service->id,
            'actor_user_id' => $actor->id,
            'container' => $containerName,
            'removed_siblings' => $removed,
        ]);

        return ['removed_siblings' => $removed, 'container' => $containerName];
    }

    /**
     * A sibling site is wholly the convert's creation: its container goes and
     * so does its service row, since the next convert makes it again from
     * what DirectAdmin holds. Quiet: the platform never messages the customer.
     */
    protected function removeSibling(Service $sibling): void
    {
        $sibling->loadMissing('containerDeployment.node');
        if ($sibling->containerDeployment && $sibling->containerDeployment->status !== 'terminated') {
            $this->deployments->terminate($sibling, notify: false);
        }
        $sibling->fresh()?->delete();
    }

    /**
     * The primary's container, files and database volume go, the proxy
     * entries are unbound and the deployment row is closed, but the backups
     * and the service row stay: that is the difference from a termination.
     */
    protected function tearDownContainer(Service $service): void
    {
        $service->loadMissing('containerDeployment.node');
        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status === 'terminated') {
            return;
        }
        if (! $deployment->node) {
            throw new \RuntimeException('The container node is missing; the old container cannot be removed safely.');
        }

        $this->deployments->validateNodeSSHCredentials($deployment->node);
        $ssh = SSHService::forNode($deployment->node);
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        try {
            $ssh->exec('cd '.escapeshellarg($containerPath).' 2>/dev/null && docker compose -f docker-compose.yml down -v; true', 300);
            $ssh->deleteDir($containerPath);
            $remaining = trim($ssh->exec(
                'if docker ps -aq --filter '.escapeshellarg('label=com.docker.compose.project='.$deployment->container_name)
                .' | grep -q . || test -e '.escapeshellarg($containerPath).'; then echo present; else echo absent; fi',
                30
            ));
            if ($remaining !== 'absent') {
                throw new \RuntimeException('The old container could not be confirmed removed; nothing else was changed.');
            }
        } finally {
            $ssh->disconnect();
        }

        $this->deployments->unbindAllDomainsForService($service);
        app(ContainerCronService::class)->deleteForService($service);
        $deployment->update(['status' => 'terminated', 'terminated_at' => now()]);
        $deployment->node->decrement('container_count');
    }
}
