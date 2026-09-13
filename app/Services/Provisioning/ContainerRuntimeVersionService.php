<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\TechStackRoutingService;

/**
 * The PHP version tab: which version a deployed Laravel, PHP or WordPress
 * stack runs, which it may switch to, and the switch itself.
 *
 * A switch is a redeploy with the new version recorded on the service:
 * the platform builds (or reuses) the runtime image for that version, or
 * re-tags the WordPress image, and recreates the app container. Files and
 * the database live in bind mounts and named volumes, so they survive.
 */
class ContainerRuntimeVersionService
{
    public function supportsService(Service $service): bool
    {
        $template = $service->effectiveContainerTemplate();

        return $template !== null
            && in_array(strtolower((string) $template->slug), ['laravel', 'php', 'wordpress'], true)
            && TechStackRoutingService::hasVersionPicker($template);
    }

    /**
     * @return array{
     *     current: ?string, current_label: string, default: ?string, default_label: string,
     *     label: string, help: ?string, options: list<array{value: string, label: string, description: ?string}>,
     *     container_running: bool, deploying: bool, template_slug: string
     * }|null
     */
    public function panelState(Service $service, ?ContainerDeployment $deployment): ?array
    {
        $template = $service->effectiveContainerTemplate();
        if (! $template || ! $this->supportsService($service)) {
            return null;
        }

        $picker = TechStackRoutingService::versionPickerPayload($template);
        $current = $this->currentVersion($service, $deployment);
        $labelFor = function (?string $version) use ($picker): string {
            foreach ($picker['options'] as $option) {
                if ($option['value'] === $version) {
                    return $option['label'];
                }
            }

            return $version ?? 'Default';
        };

        return [
            'current' => $current,
            'current_label' => $labelFor($current ?? $picker['value']),
            'default' => $picker['value'],
            'default_label' => $labelFor($picker['value']),
            'label' => $picker['label'],
            'help' => $picker['help'],
            'options' => $picker['options'],
            'container_running' => (bool) $deployment?->isRunning(),
            'deploying' => (string) ($deployment?->status ?? '') === 'deploying' || (string) ($service->status?->value ?? $service->status) === 'provisioning',
            'template_slug' => strtolower((string) $template->slug),
        ];
    }

    public function currentVersion(Service $service, ?ContainerDeployment $deployment): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $version = $deployment?->selected_version ?? ($meta['selected_version'] ?? null);

        return is_string($version) && trim($version) !== '' ? $this->normalize($service, trim($version)) : null;
    }

    /**
     * Records the version and redeploys. Returns the outcome message; throws
     * a \DomainException the customer can read when the switch is refused.
     *
     * @return array{changed: bool, from: ?string, to: ?string, message: string}
     */
    public function switch(Service $service, ?string $version): array
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $template = $service->effectiveContainerTemplate();
        if (! $template || ! $this->supportsService($service)) {
            throw new \DomainException('This stack does not offer a version choice.');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            throw new \DomainException('Deploy the application before changing its version.');
        }
        if ((string) $deployment->status === 'deploying' || (string) ($service->status?->value ?? $service->status) === 'provisioning') {
            throw new \DomainException('A deploy is already running. Wait for it to finish, then change the version.');
        }

        $picker = TechStackRoutingService::versionPickerPayload($template);
        $to = $version !== null && trim($version) !== '' ? trim($version) : null;
        if ($to !== null && ! in_array($to, array_column($picker['options'], 'value'), true)) {
            throw new \DomainException('That version is not offered for this stack.');
        }

        $from = $this->currentVersion($service, $deployment);
        $effectiveTo = $to ?? $picker['value'];
        if ($from === $effectiveTo || ($from === null && $to === null)) {
            return ['changed' => false, 'from' => $from, 'to' => $effectiveTo, 'message' => 'The application already runs '.$this->labelFor($picker, $effectiveTo).'.'];
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $previousMeta = $meta;
        $previousDeploymentVersion = $deployment->selected_version;

        if ($to === null) {
            unset($meta['selected_version']);
        } else {
            $meta['selected_version'] = $to;
        }
        $service->update(['service_meta' => $meta]);
        $service->refresh();

        try {
            app(ContainerDeploymentService::class)->deploy($service, ContainerDeployOptions::redeploy());
        } catch (\Throwable $e) {
            $service->update(['service_meta' => $previousMeta]);
            $service->containerDeployment?->update(['selected_version' => $previousDeploymentVersion]);

            throw $e;
        }

        return [
            'changed' => true,
            'from' => $from,
            'to' => $effectiveTo,
            'message' => 'Application redeployed on '.$this->labelFor($picker, $effectiveTo).'.',
        ];
    }

    /**
     * Older rows carry the template's "8.3-cli" spelling; the picker uses "8.3".
     */
    private function normalize(Service $service, string $version): string
    {
        $slug = strtolower((string) ($service->effectiveContainerTemplate()?->slug ?? ''));
        if (in_array($slug, ['laravel', 'php'], true) && preg_match('/^(\d+\.\d+)/', $version, $m) === 1) {
            return $m[1];
        }

        return $version;
    }

    /**
     * @param  array{options: list<array{value: string, label: string}>}  $picker
     */
    private function labelFor(array $picker, ?string $version): string
    {
        foreach ($picker['options'] as $option) {
            if ($option['value'] === $version) {
                return $option['label'];
            }
        }

        return $version ?? 'the default version';
    }
}
