<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerNodeVersionService
{
    public function __construct(
        private readonly ContainerApplicationRuntimeService $runtimeService,
        private readonly ContainerAppDirectoryService $appDirectory,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedVersions(ContainerTemplate $template): array
    {
        $persisted = is_array($template->versions)
            ? $template->versions
            : (json_decode((string) $template->versions, true) ?: []);

        return array_values(array_unique(array_merge(
            array_filter($persisted, 'is_string'),
            ContainerTemplate::nodeRuntimeVersions(),
        )));
    }

    /**
     * Detect and persist an automatic version, or validate a manual pin.
     *
     * @return array{selected_version: ?string, source: string, constraint: ?string, changed: bool, message: string}
     */
    public function reconcileFromHost(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
    ): array {
        $service->loadMissing('product.containerTemplate');
        $template = $service->effectiveContainerTemplate();
        if (($template?->slug ?? '') !== 'nodejs') {
            throw new \DomainException('Node version detection only supports Node.js services.');
        }

        $hostAppPath = $this->appDirectory->hostAppPath($deployment);
        $relativeRoot = $this->runtimeService->discoverNodeProjectRelativeRoot($ssh, $hostAppPath);
        $projectPath = $relativeRoot === '' ? $hostAppPath : $hostAppPath.'/'.$relativeRoot;
        $packageJson = trim($ssh->exec(
            'head -c 65536 '.escapeshellarg($projectPath.'/package.json').' 2>/dev/null || true',
            20,
        ));
        $constraint = $this->constraintFromPackageJson($packageJson);
        if ($constraint === null && $projectPath !== $hostAppPath) {
            $rootPackageJson = trim($ssh->exec(
                'head -c 65536 '.escapeshellarg($hostAppPath.'/package.json').' 2>/dev/null || true',
                20,
            ));
            $constraint = $this->constraintFromPackageJson($rootPackageJson);
        }

        if ($constraint === null) {
            $nvmrc = trim($ssh->exec(
                'head -c 128 '.escapeshellarg($projectPath.'/.nvmrc').' 2>/dev/null || true',
                10,
            ));
            if ($nvmrc === '' && $projectPath !== $hostAppPath) {
                $nvmrc = trim($ssh->exec(
                    'head -c 128 '.escapeshellarg($hostAppPath.'/.nvmrc').' 2>/dev/null || true',
                    10,
                ));
            }
            $constraint = $this->constraintFromNvmrc($nvmrc);
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $metaSelection = is_string($meta['selected_version'] ?? null) ? $meta['selected_version'] : null;
        $source = (string) ($meta['node_version_source'] ?? ($metaSelection ? 'manual' : 'auto'));
        $current = $source === 'manual'
            ? $metaSelection
            : ($deployment->selected_version ?: $metaSelection ?: $template->docker_image);
        $current = $this->selectionTag($current);

        if ($constraint === null) {
            return [
                'selected_version' => $current,
                'source' => $source,
                'constraint' => null,
                'changed' => false,
                'message' => 'No Node engine constraint declared; keeping '.($current ?: 'the template default').'.',
            ];
        }

        $allowed = $this->allowedVersions($template);
        if ($source === 'manual' && $current !== null) {
            $major = $this->majorFromVersion($current);
            if ($major === null || ! $this->majorSatisfies($major, $constraint)) {
                throw new \DomainException(
                    'package.json requires Node '.$constraint.', but this service is manually pinned to '.$current
                    .'. Choose a compatible Node version during redeploy or switch the version selector to Auto detect.'
                );
            }

            $this->persistDetection($service, $constraint, $current, 'manual');

            return [
                'selected_version' => $current,
                'source' => 'manual',
                'constraint' => $constraint,
                'changed' => false,
                'message' => 'Manual Node pin '.$current.' satisfies '.$constraint.'.',
            ];
        }

        $selected = $this->selectVersion($constraint, $current, $allowed);
        $changed = $selected !== $current;
        $deployment->update(['selected_version' => $selected]);
        $this->persistDetection($service, $constraint, $selected, 'detected');

        return [
            'selected_version' => $selected,
            'source' => 'detected',
            'constraint' => $constraint,
            'changed' => $changed,
            'message' => 'Detected package.json Node '.$constraint.'; using '.$selected.'.',
        ];
    }

    public function constraintFromPackageJson(?string $packageJson): ?string
    {
        if (! is_string($packageJson) || trim($packageJson) === '') {
            return null;
        }

        $data = json_decode($packageJson, true);
        if (! is_array($data)) {
            throw new \DomainException('package.json is not valid JSON; Node version cannot be detected safely.');
        }

        $engine = trim((string) ($data['engines']['node'] ?? ''));
        if ($engine !== '') {
            return $engine;
        }

        $volta = trim((string) ($data['volta']['node'] ?? ''));

        return $volta !== '' ? $volta : null;
    }

    public function constraintFromNvmrc(?string $nvmrc): ?string
    {
        $value = trim((string) $nvmrc);
        if ($value === '' || in_array(strtolower($value), ['node', 'stable', 'lts/*'], true)) {
            return null;
        }

        return preg_match('/^v?\d+(?:\.\d+){0,2}$/', $value) === 1 ? ltrim($value, 'v') : null;
    }

    /**
     * @param  list<string>  $allowedVersions
     */
    public function selectVersion(string $constraint, ?string $current, array $allowedVersions): string
    {
        $majors = [];
        foreach ($allowedVersions as $version) {
            $major = $this->majorFromVersion($version);
            if ($major !== null && $this->majorSatisfies($major, $constraint)) {
                $majors[] = $major;
            }
        }
        $majors = array_values(array_unique($majors));
        sort($majors);

        if ($majors === []) {
            throw new \DomainException(
                'This repository requires Node '.$constraint
                .', but the platform offers only '.implode(', ', $allowedVersions).'.'
            );
        }

        $currentMajor = $this->majorFromVersion($current);
        $targetMajor = $currentMajor !== null && in_array($currentMajor, $majors, true)
            ? $currentMajor
            : min($majors);

        $flavor = str_contains((string) $current, 'slim')
            ? '-slim'
            : (str_contains((string) $current, 'alpine') ? '-alpine' : '-alpine');
        $candidate = $targetMajor.$flavor;

        if (in_array($candidate, $allowedVersions, true)) {
            return $candidate;
        }
        if (in_array((string) $targetMajor, $allowedVersions, true)) {
            return (string) $targetMajor;
        }

        foreach ($allowedVersions as $version) {
            if ($this->majorFromVersion($version) === $targetMajor) {
                return $version;
            }
        }

        throw new \LogicException('A matching Node major was found without a selectable image tag.');
    }

    public function majorSatisfies(int $major, string $constraint): bool
    {
        $constraint = trim(str_replace(',', ' ', $constraint));
        if ($constraint === '' || in_array(strtolower($constraint), ['*', 'latest', 'node'], true)) {
            return true;
        }

        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            if (preg_match('/^v?(\d+)(?:\.\d+){0,2}\s+-\s+v?(\d+)(?:\.\d+){0,2}$/', $clause, $range) === 1) {
                if ($major >= (int) $range[1] && $major <= (int) $range[2]) {
                    return true;
                }

                continue;
            }

            $matches = preg_split('/\s+/', $clause) ?: [];
            $valid = true;
            foreach ($matches as $token) {
                if ($token === '' || $token === '*') {
                    continue;
                }
                if (preg_match('/^(>=|<=|>|<|\^|~|=)?v?(\d+)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?$/i', $token, $parts) !== 1) {
                    throw new \DomainException('Unsupported Node engine constraint: '.$constraint.'.');
                }
                $operator = $parts[1] ?? '=';
                $required = (int) $parts[2];
                $valid = $valid && match ($operator) {
                    '>=' => $major >= $required,
                    '<=' => $major <= $required,
                    '>' => $major > $required,
                    '<' => $major < $required,
                    '^', '~', '=', '' => $major === $required,
                    default => false,
                };
            }
            if ($valid) {
                return true;
            }
        }

        return false;
    }

    public function majorFromVersion(?string $version): ?int
    {
        return preg_match('/(?:^|:|v)(\d{1,2})(?:[.-]|$)/', trim((string) $version), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function selectionTag(?string $version): ?string
    {
        $value = trim((string) $version);
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, 'node:') ? substr($value, strlen('node:')) : $value;
    }

    private function persistDetection(
        Service $service,
        string $constraint,
        string $selected,
        string $source,
    ): void {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['selected_version'] = $selected;
        $meta['node_version_source'] = $source;
        $meta['node_detected_engine'] = $constraint;
        $meta['node_detected_at'] = now()->toIso8601String();
        $service->update(['service_meta' => $meta]);
    }
}
