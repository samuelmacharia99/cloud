<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * Exclusive application identity for a project-split Node container.
 *
 * A Web container builds one directory. An API container builds one directory.
 * Those roots are stored on the service and on its sibling; provision never
 * infers them from the service name or by scanning the repository to "heal"
 * a pin that already points at the other container.
 */
class ContainerExclusiveApplicationPin
{
    public const ROLE_FRONTEND = 'frontend';

    public const ROLE_BACKEND = 'backend';

    /**
     * @return self::ROLE_FRONTEND|self::ROLE_BACKEND|null
     */
    public function role(Service $service): ?string
    {
        $meta = $this->meta($service);
        $role = $meta['project_role'] ?? null;
        if (in_array($role, [self::ROLE_FRONTEND, self::ROLE_BACKEND], true)) {
            return $role;
        }

        $selfId = (int) $service->id;
        $backendId = (int) ($meta['backend_service_id'] ?? 0);
        $frontendId = (int) ($meta['frontend_service_id'] ?? 0);

        if ($selfId > 0 && $frontendId === $selfId && $backendId > 0 && $backendId !== $selfId) {
            return self::ROLE_FRONTEND;
        }
        if ($selfId > 0 && $backendId === $selfId && $frontendId > 0 && $frontendId !== $selfId) {
            return self::ROLE_BACKEND;
        }
        if ($backendId > 0 && $backendId !== $selfId && $frontendId !== $selfId) {
            return self::ROLE_FRONTEND;
        }
        if ($frontendId > 0 && $frontendId !== $selfId && ($backendId === $selfId || $backendId === 0)) {
            return self::ROLE_BACKEND;
        }

        return null;
    }

    public function isExclusive(Service $service): bool
    {
        return $this->role($service) !== null;
    }

    public function sibling(Service $service): ?Service
    {
        $meta = $this->meta($service);
        $role = $this->role($service);
        $selfId = (int) $service->id;
        $candidateId = match ($role) {
            self::ROLE_FRONTEND => (int) ($meta['backend_service_id'] ?? $meta['sibling_service_id'] ?? 0),
            self::ROLE_BACKEND => (int) ($meta['frontend_service_id'] ?? $meta['sibling_service_id'] ?? 0),
            default => 0,
        };

        if ($candidateId <= 0 || $candidateId === $selfId) {
            return null;
        }

        if ($service->relationLoaded('project') && $service->project) {
            $related = $service->project->services->first(
                fn (Service $candidate): bool => (int) $candidate->id === $candidateId
            );
            if ($related instanceof Service) {
                return $related;
            }
        }

        return Service::query()->find($candidateId);
    }

    /**
     * Stored root for this container, or empty/conflict. Never scans the repo.
     *
     * @return array{state: 'found'|'empty'|'conflict', root: ?string, rejected: ?string}
     */
    public function storedApplicationRoot(Service $service, ?string $operatorOverride = null): array
    {
        $forbidden = $this->forbiddenRoots($service);
        $rejected = null;
        $empty = true;

        foreach ($this->ownRootCandidates($service, $operatorOverride) as $candidate) {
            $empty = false;
            if (in_array($candidate, $forbidden, true)) {
                $rejected = $candidate;

                continue;
            }

            return ['state' => 'found', 'root' => $candidate, 'rejected' => null];
        }

        $restored = $this->siblingRememberedRoot($service);
        if ($restored !== null && ! in_array($restored, $forbidden, true)) {
            return ['state' => 'found', 'root' => $restored, 'rejected' => null];
        }

        if ($rejected !== null) {
            return ['state' => 'conflict', 'root' => null, 'rejected' => $rejected];
        }

        return ['state' => $empty ? 'empty' : 'conflict', 'root' => null, 'rejected' => $rejected];
    }

    public function remember(Service $service, string $root, ?string $siblingRoot = null): void
    {
        $meta = $this->meta($service);
        $meta['node_application_root'] = $root;
        $meta['node_project_root'] = $root;
        $meta['node_backend_root'] = $root;
        unset($meta['node_frontend_root']);
        if ($siblingRoot !== null && $siblingRoot !== '') {
            $meta['sibling_application_root'] = $siblingRoot;
        }
        $service->service_meta = $meta;
    }

    /**
     * Write exclusive roots and the sibling ledger on both members of the pair.
     */
    public function rememberPair(Service $service, string $root, ?Service $sibling = null, ?string $siblingRoot = null): void
    {
        $sibling ??= $this->sibling($service);
        $resolvedSiblingRoot = $siblingRoot
            ?? ($sibling instanceof Service ? $this->ownUnconflictingRoot($sibling, $root) : null);

        $this->remember($service, $root, $resolvedSiblingRoot);
        $this->saveIfPersisted($service);

        if ($sibling instanceof Service && $resolvedSiblingRoot !== null && $resolvedSiblingRoot !== '') {
            $this->remember($sibling, $resolvedSiblingRoot, $root);
            $this->saveIfPersisted($sibling);
        } elseif ($sibling instanceof Service) {
            $meta = $this->meta($sibling);
            $meta['sibling_application_root'] = $root;
            $sibling->service_meta = $meta;
            $this->saveIfPersisted($sibling);
        }
    }

    /**
     * Persist the sibling ledger from roots we already trust. Does not guess.
     */
    public function reconcilePair(Service $service): void
    {
        if (! $this->isExclusive($service)) {
            return;
        }

        $sibling = $this->sibling($service);
        $own = $this->ownUnconflictingRoot($service);
        $other = $sibling instanceof Service ? $this->ownUnconflictingRoot($sibling) : null;

        if ($own !== null) {
            $this->remember($service, $own, $other);
            $this->saveIfPersisted($service);
        }

        if ($sibling instanceof Service && $other !== null) {
            $this->remember($sibling, $other, $own);
            $this->saveIfPersisted($sibling);
        } elseif ($sibling instanceof Service && $own !== null) {
            $meta = $this->meta($sibling);
            if (trim((string) ($meta['sibling_application_root'] ?? '')) === '') {
                $meta['sibling_application_root'] = $own;
                $sibling->service_meta = $meta;
                $this->saveIfPersisted($sibling);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function applyOperatorRootSelection(array $meta, array $validated): array
    {
        $probe = new Service(['service_meta' => $meta]);
        $exclusive = $this->isExclusive($probe);

        foreach (['backend_root' => 'node_backend_root', 'frontend_root' => 'node_frontend_root'] as $input => $key) {
            $value = trim((string) ($validated[$input] ?? ''));
            if ($value !== '') {
                $meta[$key] = $value;
                if ($key === 'node_backend_root' && $exclusive) {
                    $meta['node_application_root'] = $value;
                    $meta['node_project_root'] = $value;
                }

                continue;
            }

            if ($exclusive && in_array($key, ['node_backend_root'], true)) {
                continue;
            }

            unset($meta[$key]);
        }

        if ($exclusive) {
            $application = trim((string) ($meta['node_application_root'] ?? $meta['node_project_root'] ?? $meta['node_backend_root'] ?? ''));
            if ($application !== '') {
                $meta['node_application_root'] = $application;
                $meta['node_project_root'] = $application;
                $meta['node_backend_root'] = $application;
            }
        }

        if (($meta['frontend'] ?? 'none') === 'none') {
            unset($meta['node_frontend_root']);
        }
        unset($meta['node_workloads']);

        return $meta;
    }

    public function conflictMessage(Service $service, string $rejected): string
    {
        $role = $this->role($service) === self::ROLE_FRONTEND ? 'Web' : 'API';
        $needed = $this->role($service) === self::ROLE_FRONTEND
            ? 'frontend directory (for example apps/mobile)'
            : 'API directory (for example apps/api)';

        return "This {$role} container is pinned to the sibling application directory '{$rejected}'. "
            ."Set the {$needed} and retry deploy.";
    }

    public function missingMessage(Service $service): string
    {
        $role = $this->role($service) === self::ROLE_FRONTEND ? 'Web' : 'API';
        $needed = $this->role($service) === self::ROLE_FRONTEND
            ? 'frontend directory (for example apps/mobile)'
            : 'API directory (for example apps/api)';

        return "This {$role} container has no application directory pinned. Set the {$needed} and retry deploy.";
    }

    /**
     * @return list<string>
     */
    private function ownRootCandidates(Service $service, ?string $operatorOverride): array
    {
        $meta = $this->meta($service);
        $values = [
            $meta['node_application_root'] ?? null,
            $meta['node_project_root'] ?? null,
            $meta['node_backend_root'] ?? null,
            $operatorOverride,
        ];

        $roots = [];
        foreach ($values as $value) {
            $root = $this->sanitizeOptional($value);
            if ($root !== null && ! in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function forbiddenRoots(Service $service): array
    {
        $meta = $this->meta($service);
        $forbidden = [];
        $ownSiblingPointer = $this->sanitizeOptional($meta['sibling_application_root'] ?? null);
        if ($ownSiblingPointer !== null) {
            $forbidden[] = $ownSiblingPointer;
        }

        $sibling = $this->sibling($service);
        if ($sibling instanceof Service) {
            $siblingOwn = $this->ownUnconflictingRoot($sibling, ignoreSibling: true);
            if ($siblingOwn !== null) {
                $forbidden[] = $siblingOwn;
            }
        }

        return array_values(array_unique($forbidden));
    }

    private function siblingRememberedRoot(Service $service): ?string
    {
        $sibling = $this->sibling($service);
        if (! $sibling instanceof Service) {
            return null;
        }

        return $this->sanitizeOptional($this->meta($sibling)['sibling_application_root'] ?? null);
    }

    private function ownUnconflictingRoot(Service $service, ?string $forbidden = null, bool $ignoreSibling = false): ?string
    {
        $blocked = [];
        if ($forbidden !== null) {
            $blocked[] = $forbidden;
        }
        if (! $ignoreSibling) {
            $blocked = array_merge($blocked, $this->forbiddenRoots($service));
        } else {
            $pointer = $this->sanitizeOptional($this->meta($service)['sibling_application_root'] ?? null);
            if ($pointer !== null) {
                $blocked[] = $pointer;
            }
        }

        foreach ($this->ownRootCandidates($service, null) as $candidate) {
            if (! in_array($candidate, $blocked, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitizeOptional(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return app(ContainerNodeWorkloadTopologyService::class)->sanitizeRelativeRoot($value);
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Service $service): array
    {
        return is_array($service->service_meta) ? $service->service_meta : [];
    }

    private function saveIfPersisted(Service $service): void
    {
        if (! $service->exists) {
            return;
        }

        $service->update(['service_meta' => $this->meta($service)]);
    }
}
