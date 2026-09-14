<?php

namespace App\Services\Provisioning;

use App\Jobs\ConvertDirectAdminProjectSiteJob;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\Product;
use App\Models\Service;

/**
 * Re-run a DirectAdmin → Application Hosting convert on the rows it already
 * made. The primary is put back on its DirectAdmin product from the snapshot
 * taken at the first attempt and re-queued with the same options; a sibling
 * site is re-queued on its own service. Nothing here creates a service.
 */
class DaConvertRetryService
{
    public function __construct(private DaConvertProgress $progress) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function retry(Service $service): array
    {
        return $this->progress->isSiblingSite($service)
            ? $this->retrySite($service)
            : $this->retryPrimary($service);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function retrySite(Service $sibling): array
    {
        $convert = $this->progress->convertMeta($sibling);
        if ($this->progress->isActive($convert) && ! $this->progress->looksStuck($convert)) {
            return ['ok' => false, 'message' => 'This site is still converting. Watch the live terminal.'];
        }

        $released = $this->progress->releaseStaleNodeLock($sibling);
        $this->progress->merge($sibling, [
            'status' => 'queued',
            'mode' => DaConvertProgress::MODE_SITE,
            'queued_at' => now()->toIso8601String(),
            'retried_at' => now()->toIso8601String(),
            'attempt' => (int) ($convert['attempt'] ?? 0) + 1,
            'last_error' => $convert['error'] ?? null,
            'error' => null,
            'steps' => [],
            'phase' => 'queued',
            'phase_fraction' => 0.0,
            'phase_detail' => '',
            'completed_at' => null,
            'failed_at' => null,
        ]);
        $sibling->update(['status' => 'pending']);

        ConvertDirectAdminProjectSiteJob::dispatch((int) $sibling->id)->afterResponse();

        return [
            'ok' => true,
            'message' => 'Site convert re-queued on the same service.'.($released ? ' A stale node lock from a crashed run was cleared.' : '').' Watch the live terminal.',
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function retryPrimary(Service $service): array
    {
        $convert = $this->progress->convertMeta($service);
        if (! $this->progress->canRetryPrimary($service, $convert)) {
            $status = (string) ($convert['status'] ?? '');
            $message = match (true) {
                $convert === [] => 'This service has no DirectAdmin convert to retry.',
                in_array($status, DaConvertProgress::STATUSES_ACTIVE, true) => 'Convert is still running. Watch the live terminal.',
                default => 'This convert cannot be retried: the DirectAdmin snapshot or target product is missing.',
            };

            return ['ok' => false, 'message' => $message];
        }

        $options = $this->resolveOptions($service, $convert);
        if (! $options['product']) {
            return ['ok' => false, 'message' => 'The Application Hosting product from the first attempt no longer exists or is inactive.'];
        }

        $this->restoreDirectAdminRow($service);
        $released = $this->progress->releaseStaleNodeLock($service);

        $this->progress->merge($service, [
            'status' => 'queued',
            'mode' => DaConvertProgress::MODE_PRIMARY,
            'queued_at' => now()->toIso8601String(),
            'retried_at' => now()->toIso8601String(),
            'last_error' => $convert['error'] ?? null,
            'error' => null,
            'phase' => 'queued',
            'phase_fraction' => 0.0,
            'phase_detail' => '',
            'completed_at' => null,
            'failed_at' => null,
            'options' => $options['stored'],
            'target_product_id' => $options['product']->id,
            'target_product_name' => $options['product']->name,
        ]);

        ConvertDirectAdminServiceToContainerJob::dispatch(
            (int) $service->id,
            (int) $options['product']->id,
            (bool) $options['stored']['acknowledge_mail_pull'],
            $options['stored']['database_name'],
            (bool) $options['stored']['acknowledge_addon_sites'],
            $options['stored']['email_product_id'],
        )->afterResponse();

        return [
            'ok' => true,
            'message' => 'Convert re-queued on the same service. Existing project, sibling sites and the Mailcow email service are reused.'
                .($released ? ' A stale node lock from a crashed run was cleared.' : '').' Watch the live terminal.',
        ];
    }

    /**
     * Put the billing row back on DirectAdmin from da_convert.previous so
     * convertInPlace's shared-hosting preflight accepts it again. A row that
     * is already on DirectAdmin (rollback ran) is left alone.
     */
    public function restoreDirectAdminRow(Service $service): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $convert = is_array($meta['da_convert'] ?? null) ? $meta['da_convert'] : [];
        $previous = is_array($convert['previous'] ?? null) ? $convert['previous'] : [];

        $updates = [];
        if ($service->provisioningDriver() === 'container' || ! $service->isSharedHosting()) {
            $updates['provisioning_driver_key'] = $previous['provisioning_driver_key'] ?? 'directadmin';
            if (! empty($previous['product_id'])) {
                $updates['product_id'] = $previous['product_id'];
            }
            if (array_key_exists('node_id', $previous)) {
                $updates['node_id'] = $previous['node_id'];
            }
            if (! empty($previous['status'])) {
                $updates['status'] = $previous['status'];
            }
        }

        $legacy = is_array($meta['da_legacy'] ?? null) ? $meta['da_legacy'] : [];
        $metaChanged = false;
        foreach (['username', 'domain', 'password'] as $key) {
            if (! empty($legacy[$key]) && blank($meta[$key] ?? null)) {
                $meta[$key] = $legacy[$key];
                $metaChanged = true;
            }
        }
        if ($metaChanged) {
            $updates['service_meta'] = $meta;
        }

        if ($updates !== []) {
            $service->update($updates);
            $service->refresh();
        }
    }

    /**
     * Options from the first attempt, falling back to what older meta recorded.
     *
     * @param  array<string, mixed>  $convert
     * @return array{product: ?Product, stored: array{product_id: int, email_product_id: ?int, database_name: ?string, acknowledge_mail_pull: bool, acknowledge_addon_sites: bool}}
     */
    private function resolveOptions(Service $service, array $convert): array
    {
        $stored = is_array($convert['options'] ?? null) ? $convert['options'] : [];
        $productId = (int) ($stored['product_id'] ?? $convert['target_product_id'] ?? 0);
        $product = $productId > 0
            ? Product::query()->where('type', 'container_hosting')->where('is_active', true)->find($productId)
            : null;

        $emailProductId = isset($stored['email_product_id']) ? (int) $stored['email_product_id'] : 0;
        if ($emailProductId <= 0) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $emailServiceId = (int) ($meta['da_legacy']['email_service_id'] ?? $meta['mailcow_migration']['email_service_id'] ?? 0);
            $emailProductId = $emailServiceId > 0
                ? (int) (Service::query()->whereKey($emailServiceId)->value('product_id') ?? 0)
                : 0;
        }

        return [
            'product' => $product,
            'stored' => [
                'product_id' => $productId,
                'email_product_id' => $emailProductId > 0 ? $emailProductId : null,
                'database_name' => isset($stored['database_name']) && $stored['database_name'] !== '' ? (string) $stored['database_name'] : null,
                // Acknowledgements were given at the first attempt; a retry keeps them.
                'acknowledge_mail_pull' => (bool) ($stored['acknowledge_mail_pull'] ?? true),
                'acknowledge_addon_sites' => (bool) ($stored['acknowledge_addon_sites'] ?? true),
            ],
        ];
    }
}
