<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Lightweight staging link: pair a production container with a sibling staging container
 * of the same template, and optionally sync environment variables.
 */
class ContainerStagingService
{
    public function __construct(
        private ContainerEnvironmentService $environment,
    ) {}

    public function panelState(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $stagingId = isset($meta['staging_service_id']) ? (int) $meta['staging_service_id'] : null;
        $staging = $stagingId ? Service::with('product.containerTemplate')->find($stagingId) : null;

        return [
            'staging_service_id' => $staging?->id,
            'staging_name' => $staging?->name,
            'candidates' => $this->candidates($service)->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Service>
     */
    public function candidates(Service $service)
    {
        $service->loadMissing('product');
        $templateId = $service->product?->container_template_id;

        return Service::query()
            ->where('user_id', $service->user_id)
            ->where('id', '!=', $service->id)
            ->whereHas('product', function ($q) use ($templateId) {
                $q->where('type', 'container_hosting');
                if ($templateId) {
                    $q->where('container_template_id', $templateId);
                }
            })
            ->whereHas('containerDeployment')
            ->orderByDesc('id')
            ->get();
    }

    public function link(Service $production, Service $staging): void
    {
        if ($production->user_id !== $staging->user_id) {
            throw new \InvalidArgumentException('Staging service must belong to the same account.');
        }

        if (! $production->isContainerHosting() || ! $staging->isContainerHosting()) {
            throw new \InvalidArgumentException('Both services must be application hosting containers.');
        }

        $production->loadMissing('product');
        $staging->loadMissing('product');
        if ($production->product?->container_template_id
            && $staging->product?->container_template_id
            && $production->product->container_template_id !== $staging->product->container_template_id) {
            throw new \InvalidArgumentException('Staging must use the same stack/template as production.');
        }

        $meta = is_array($production->service_meta) ? $production->service_meta : [];
        $meta['staging_service_id'] = $staging->id;
        $production->update(['service_meta' => $meta]);

        $stagingMeta = is_array($staging->service_meta) ? $staging->service_meta : [];
        $stagingMeta['production_service_id'] = $production->id;
        $stagingMeta['is_staging'] = true;
        $staging->update(['service_meta' => $stagingMeta]);
    }

    public function unlink(Service $production): void
    {
        $meta = is_array($production->service_meta) ? $production->service_meta : [];
        $stagingId = $meta['staging_service_id'] ?? null;
        unset($meta['staging_service_id']);
        $production->update(['service_meta' => $meta]);

        if ($stagingId) {
            $staging = Service::find($stagingId);
            if ($staging) {
                $stagingMeta = is_array($staging->service_meta) ? $staging->service_meta : [];
                unset($stagingMeta['production_service_id'], $stagingMeta['is_staging']);
                $staging->update(['service_meta' => $stagingMeta]);
            }
        }
    }

    /**
     * Copy customer-editable env vars from production to staging (restarts staging).
     */
    public function syncEnvironment(Service $production): array
    {
        $meta = is_array($production->service_meta) ? $production->service_meta : [];
        $stagingId = $meta['staging_service_id'] ?? null;
        $staging = $stagingId ? Service::with('containerDeployment')->find($stagingId) : null;
        if (! $staging?->containerDeployment) {
            throw new \InvalidArgumentException('Link a staging container before syncing environment.');
        }

        $prodState = $this->environment->buildPanelState($production, $production->containerDeployment);
        $variables = [];
        foreach ($prodState['variables'] ?? [] as $row) {
            $key = $row['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (! empty($row['platform_managed'])) {
                continue;
            }
            $variables[$key] = (string) ($row['value'] ?? '');
        }

        return $this->environment->updateVariables($staging, $variables, restart: true);
    }
}
