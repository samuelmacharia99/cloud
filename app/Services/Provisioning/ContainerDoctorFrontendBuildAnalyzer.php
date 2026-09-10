<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * Finds public build-time settings that never reached the shipped bundle.
 *
 * EXPO_PUBLIC_*, NEXT_PUBLIC_* and VITE_* values are substituted into the
 * JavaScript when the frontend is exported, not read at run time. Saving one
 * afterwards changes the container environment and nothing else, so the app
 * keeps reporting the setting as missing while the console shows it set. The
 * customer has no way to see why.
 *
 * Ground truth rather than bookkeeping: the value is stale when the frontend
 * source references the name but the built output does not contain the value.
 * Requiring the source reference is what keeps unused keys from being flagged.
 */
class ContainerDoctorFrontendBuildAnalyzer
{
    public const TREAT_ACTION = 'rebuild_frontend_bundle';

    public const MOVE_TREAT_ACTION = 'move_public_env_to_web';

    /** @var list<string> */
    public const PUBLIC_BUILD_PREFIXES = ['EXPO_PUBLIC_', 'NEXT_PUBLIC_', 'VITE_', 'NUXT_PUBLIC_', 'REACT_APP_'];

    /** Bounded so a wide environment cannot turn one diagnosis into dozens of greps. */
    private const MAX_KEYS = 8;

    /** @var list<string> */
    private const BUILD_DIRECTORIES = ['dist', 'build', '.next', 'web-build', 'out'];

    /**
     * @return list<array<string, mixed>>
     */
    public function findings(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
    ): array {
        $candidates = $this->publicBuildValues($deployment);
        if ($candidates === []) {
            return [];
        }

        $role = app(ContainerExclusiveApplicationPin::class)->role($service);

        // An API container in a split project never builds the browser app, so a
        // public value saved here cannot reach any bundle no matter how often it
        // is redeployed. That is a placement problem, not a stale build.
        if ($role === ContainerExclusiveApplicationPin::ROLE_BACKEND) {
            return $this->misplacedOnApiFindings($service, array_keys($candidates));
        }

        $frontendRoot = $this->frontendRoot($service, $role);
        if ($frontendRoot === null) {
            return [];
        }

        $hostAppPath = rtrim(app(ContainerAppDirectoryService::class)->hostAppPath($deployment), '/');
        $frontendPath = $frontendRoot === '.' ? $hostAppPath : $hostAppPath.'/'.$frontendRoot;

        $buildPath = $this->buildDirectory($ssh, $frontendPath);
        if ($buildPath === null) {
            // Nothing has been exported yet, which a deploy handles on its own.
            return [];
        }

        $stale = [];
        foreach ($candidates as $key => $value) {
            if (! $this->sourceReferences($ssh, $frontendPath, $key)) {
                continue;
            }

            if ($this->buildContains($ssh, $buildPath, $value)) {
                continue;
            }

            $stale[$key] = $value;
        }

        if ($stale === []) {
            return [];
        }

        return [$this->finding(array_keys($stale), $frontendRoot)];
    }

    /**
     * Public build keys with a usable value, in a stable order.
     *
     * @return array<string, string>
     */
    public function publicBuildValues(ContainerDeployment $deployment): array
    {
        $values = is_array($deployment->env_values) ? $deployment->env_values : [];
        $candidates = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! $this->isPublicBuildKey($key)) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            // A relative path such as /api is too short and too common to search
            // a minified bundle for without matching something unrelated.
            if (strlen($value) < 8) {
                continue;
            }

            $candidates[$key] = $value;
        }

        ksort($candidates);

        return array_slice($candidates, 0, self::MAX_KEYS, true);
    }

    public function isPublicBuildKey(string $key): bool
    {
        foreach (self::PUBLIC_BUILD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function finding(array $keys, string $frontendRoot): array
    {
        $names = implode(', ', $keys);

        return [
            'id' => 'frontend_public_env_stale',
            'severity' => 'warning',
            'title' => 'Frontend was built before these settings were saved',
            'summary' => "The frontend bundle does not contain the current value of {$names}. "
                .'These are baked into the JavaScript when the app is built, so saving them afterwards '
                .'does not change what visitors download. The app will keep reporting them as missing '
                .'until the frontend is rebuilt.',
            'evidence' => array_map(
                fn (string $key): string => $key.' is set on the service but absent from '.$frontendRoot.'/ build output',
                $keys,
            ),
            'treat_action' => self::TREAT_ACTION,
            'treat_label' => 'Rebuild frontend',
            'manual_steps' => [
                'Click Rebuild frontend — rebuilds the browser app with the current environment and restarts it.',
                'Confirm the value under Environment first; a rebuild bakes in whatever is saved there now.',
                'Native mobile builds are built on your own machine, so rebuild and resubmit those separately.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * Public build keys sitting on an API container whose sibling Web container
     * does not have them. Once the sibling has a value the placement is fixed,
     * so a leftover copy here stops being worth reporting.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    public function misplacedOnApiFindings(Service $service, array $keys): array
    {
        $sibling = app(ContainerExclusiveApplicationPin::class)->sibling($service);
        $siblingEnv = is_array($sibling?->containerDeployment?->env_values)
            ? $sibling->containerDeployment->env_values
            : [];

        $missingOnWeb = array_values(array_filter(
            $keys,
            fn (string $key): bool => trim((string) ($siblingEnv[$key] ?? '')) === '',
        ));

        if ($missingOnWeb === []) {
            return [];
        }

        return [$this->misplacedFinding($missingOnWeb, $sibling?->name)];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function misplacedFinding(array $keys, ?string $webServiceName = null): array
    {
        $names = implode(', ', $keys);
        $target = $webServiceName !== null && trim($webServiceName) !== ''
            ? 'the Web container ('.trim($webServiceName).')'
            : 'the Web container in this project';

        return [
            'id' => 'frontend_public_env_on_api_container',
            'severity' => 'warning',
            'title' => 'Frontend settings are saved on the API container',
            'summary' => "{$names} only take effect where the browser app is built, and this service is the API. "
                .'Each container in a split project has its own environment, so a value saved here never reaches '
                ."the frontend and the app keeps reporting it as missing. It belongs on {$target}.",
            'evidence' => array_map(
                fn (string $key): string => $key.' is set on this API container and unset on the Web container',
                $keys,
            ),
            'treat_action' => self::MOVE_TREAT_ACTION,
            'treat_label' => 'Copy to Web container',
            'manual_steps' => [
                'Click Copy to Web container — writes these onto the Web service and rebuilds its bundle.',
                'Or open the Web service yourself and add them under Environment, then apply.',
                'Nothing is removed from this API container; a leftover copy here is harmless.',
            ],
            'source' => 'live',
        ];
    }

    /**
     * Where the browser app is built. A split stack keeps it beside the API; a
     * split project gives the Web container its own pinned root.
     */
    private function frontendRoot(Service $service, ?string $role): ?string
    {
        $meta = $service->service_meta;

        if (data_get($meta, 'node_workloads.topology') === 'split_web_api') {
            $root = trim((string) data_get($meta, 'node_workloads.frontend.root', ''), '/');

            return $root === '' ? null : $root;
        }

        if ($role === ContainerExclusiveApplicationPin::ROLE_FRONTEND) {
            $root = trim((string) data_get($meta, 'node_workloads.backend.root', ''), '/');

            return $root === '' ? '.' : $root;
        }

        return null;
    }

    private function buildDirectory(SSHService $ssh, string $frontendPath): ?string
    {
        foreach (self::BUILD_DIRECTORIES as $directory) {
            $path = $frontendPath.'/'.$directory;
            if ($this->directoryExists($ssh, $path)) {
                return $path;
            }
        }

        return null;
    }

    private function directoryExists(SSHService $ssh, string $path): bool
    {
        return $this->probe($ssh, 'test -d '.escapeshellarg($path).' && echo yes || echo no') === 'yes';
    }

    private function sourceReferences(SSHService $ssh, string $frontendPath, string $key): bool
    {
        $command = 'grep -rqsF --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=build'
            .' --exclude-dir=.next --exclude-dir=web-build --exclude-dir=out --exclude-dir=.git -- '
            .escapeshellarg($key).' '.escapeshellarg($frontendPath).' && echo yes || echo no';

        return $this->probe($ssh, $command) === 'yes';
    }

    /**
     * Only a definite "no" counts as absent. A probe that could not run must
     * not be read as evidence, or an unreadable path becomes a false finding.
     */
    private function buildContains(SSHService $ssh, string $buildPath, string $value): bool
    {
        $command = 'grep -rqsF -- '.escapeshellarg($value).' '.escapeshellarg($buildPath).' && echo yes || echo no';

        return $this->probe($ssh, $command) !== 'no';
    }

    /**
     * A probe that cannot answer is treated as "no finding" rather than a
     * failure. Doctor must never break a diagnosis over one unreadable path.
     */
    private function probe(SSHService $ssh, string $command): string
    {
        try {
            return trim($ssh->exec($command, 20));
        } catch (\Throwable) {
            return '';
        }
    }
}
