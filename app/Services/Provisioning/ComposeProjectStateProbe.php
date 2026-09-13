<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

/**
 * Every container of every compose stack on a node, in one round-trip.
 *
 * Stacks run from /opt/talksasa/containers/{container_name} with no project
 * flag, so the compose project label equals the deployment's container_name;
 * the compose service label names the member inside it. Matching on those
 * labels is exact regardless of how a container was named.
 */
final class ComposeProjectStateProbe
{
    public const TIMEOUT_SECONDS = 20;

    private const FORMAT = '{{.Label "com.docker.compose.project"}}|{{.Label "com.docker.compose.service"}}|{{.Names}}|{{.State}}|{{.Status}}|{{.Ports}}';

    /**
     * @return array<string, array{project: string, service: string, name: string, state: string, status: string, ports: string}> keyed by container name
     */
    public function probeProject(SSHService $ssh, string $composeProject): array
    {
        $rows = self::parse($ssh->exec(self::projectCommand($composeProject), self::TIMEOUT_SECONDS));

        return $rows[$composeProject] ?? [];
    }

    /**
     * @return array<string, array<string, array{project: string, service: string, name: string, state: string, status: string, ports: string}>> project => container name => row
     */
    public function probeNode(SSHService $ssh): array
    {
        return self::parse($ssh->exec(self::nodeCommand(), self::TIMEOUT_SECONDS));
    }

    public static function projectCommand(string $composeProject): string
    {
        return 'docker ps -a --filter '.escapeshellarg('label=com.docker.compose.project='.$composeProject)
            .' --format '.escapeshellarg(self::FORMAT);
    }

    public static function nodeCommand(): string
    {
        return 'docker ps -a --filter label=com.docker.compose.project --format '.escapeshellarg(self::FORMAT);
    }

    /**
     * Pure parser for the format above. Rows without a project label or a
     * name are dropped; the port column is last so it may carry anything.
     *
     * The commands deliberately do not swallow a non-zero exit: a docker
     * daemon that is down must surface as "unreachable" (SSHService::exec
     * throws), not as a stack with no containers.
     *
     * @return array<string, array<string, array{project: string, service: string, name: string, state: string, status: string, ports: string}>>
     */
    public static function parse(string $output): array
    {
        $projects = [];

        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_pad(explode('|', $line, 6), 6, '');
            $project = trim($parts[0]);
            $name = trim($parts[2]);
            if ($project === '' || $name === '') {
                continue;
            }

            $projects[$project][$name] = [
                'project' => $project,
                'service' => trim($parts[1]),
                'name' => $name,
                'state' => strtolower(trim($parts[3])),
                'status' => trim($parts[4]),
                'ports' => trim($parts[5]),
            ];
        }

        return $projects;
    }
}
