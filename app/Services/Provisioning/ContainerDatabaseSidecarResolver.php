<?php

namespace App\Services\Provisioning;

use App\Models\DatabaseTemplate;
use App\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * What kind of database a stack actually runs, whether or not the platform
 * sold it one.
 *
 * A DatabaseTemplate record exists only when the database was chosen at
 * checkout. Stacks whose container template ships its own sidecar, WordPress
 * above all, never get one, because the template already declares the service.
 * Code that asked for the record to learn the type therefore concluded these
 * sites had no database at all, and refused to repair a MySQL container that
 * was running and healthy the whole time.
 *
 * The running stack is the authority here. The record is only needed when
 * something has to be built that does not exist yet.
 */
class ContainerDatabaseSidecarResolver
{
    private const TYPES = ['mariadb', 'mysql', 'postgresql', 'mongodb'];

    /**
     * Null only when nothing anywhere declares a database for this service.
     */
    public function typeForService(Service $service, ?string $liveCompose = null): ?string
    {
        $sold = $this->soldDatabaseTemplate($service);
        if ($sold && trim((string) $sold->type) !== '') {
            return strtolower(trim((string) $sold->type));
        }

        $running = $this->typeFromComposeYaml($liveCompose);
        if ($running !== null) {
            return $running;
        }

        return $this->typeFromTemplate($service);
    }

    /**
     * The database this service was actually sold, if any.
     *
     * The checkout choice is read straight off the service rather than through
     * resolveDatabaseTemplateForService, which returns nothing when the
     * container template cannot be resolved. A customer who paid for Postgres
     * should not be handed MySQL because a template slug drifted.
     */
    private function soldDatabaseTemplate(Service $service): ?DatabaseTemplate
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $databaseId = (int) ($meta['database_id'] ?? 0);

        if ($databaseId > 0) {
            $template = DatabaseTemplate::find($databaseId);
            if ($template) {
                return $template;
            }
        }

        return app(ContainerDeploymentService::class)->resolveDatabaseTemplateForService($service);
    }

    /**
     * The type declared by a rendered compose file, read from the sidecar's
     * image first because a service named `db` says nothing on its own.
     */
    public function typeFromComposeYaml(?string $yaml): ?string
    {
        if (! is_string($yaml) || trim($yaml) === '') {
            return null;
        }

        try {
            $compose = Yaml::parse($yaml);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($compose['services'] ?? null)) {
            return null;
        }

        return $this->typeFromServiceDefinitions($compose['services']);
    }

    /**
     * The type the container template ships, for a stack whose compose could
     * not be read off the node.
     */
    private function typeFromTemplate(Service $service): ?string
    {
        $services = $service->effectiveContainerTemplate()?->compose_services;

        return is_array($services) ? $this->typeFromServiceDefinitions($services) : null;
    }

    /**
     * @param  array<mixed>  $services
     */
    private function typeFromServiceDefinitions(array $services): ?string
    {
        $byName = null;

        foreach ($services as $name => $definition) {
            $image = is_array($definition) ? strtolower((string) ($definition['image'] ?? '')) : '';
            $fromImage = $this->matchType($image);
            if ($fromImage !== null) {
                return $fromImage;
            }

            // Held back rather than returned: an image is a stronger signal
            // than a service key, and a later service may still carry one.
            $byName ??= $this->matchType(strtolower((string) $name));
        }

        return $byName;
    }

    private function matchType(string $haystack): ?string
    {
        if ($haystack === '') {
            return null;
        }

        // mariadb before mysql: the mariadb image mentions both.
        foreach (self::TYPES as $type) {
            if (str_contains($haystack, $type)) {
                return $type;
            }
        }

        if (str_contains($haystack, 'postgres')) {
            return 'postgresql';
        }

        return str_contains($haystack, 'mongo') ? 'mongodb' : null;
    }
}
