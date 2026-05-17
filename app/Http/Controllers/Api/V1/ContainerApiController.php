<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Service;
use App\Models\ContainerBackup;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerApiController
{
    /**
     * Get container deployment details
     */
    public function show(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        $deployment = $service->containerDeployment;

        if (!$deployment) {
            return response()->json(['error' => 'No container deployment found'], 404);
        }

        return response()->json([
            'id' => $deployment->id,
            'service_id' => $service->id,
            'container_name' => $deployment->container_name,
            'status' => $deployment->status,
            'port' => $deployment->assigned_port,
            'internal_ip' => $deployment->internal_ip,
            'deployed_at' => $deployment->deployed_at?->toIso8601String(),
            'last_status_check_at' => $deployment->last_status_check_at?->toIso8601String(),
            'auto_restart' => $deployment->auto_restart,
            'restart_policy' => $deployment->restart_policy,
        ]);
    }

    /**
     * Start a stopped container
     */
    public function start(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $deploymentService = new ContainerDeploymentService();
            $deploymentService->resume($service);

            return response()->json(['message' => 'Container started']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Stop a running container
     */
    public function stop(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $deploymentService = new ContainerDeploymentService();
            $deploymentService->suspend($service);

            return response()->json(['message' => 'Container stopped']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restart a container
     */
    public function restart(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $deploymentService = new ContainerDeploymentService();
            $deploymentService->restart($service);

            return response()->json(['message' => 'Container restarted']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get container logs
     */
    public function logs(Service $service, Request $request): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $lines = $request->get('lines', 100);
            $deploymentService = new ContainerDeploymentService();
            $logs = $deploymentService->getLogs($service, $lines);

            return response()->json(['logs' => $logs]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get container metrics
     */
    public function metrics(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return response()->json(['error' => 'No deployment found'], 404);
            }

            $metrics = $deployment->metrics()->latest()->first();

            return response()->json([
                'cpu_percent' => $metrics?->cpu_percent ?? 0,
                'memory_percent' => $metrics?->memory_percent ?? 0,
                'memory_mb' => $metrics?->memory_mb ?? 0,
                'network_in_bytes' => $metrics?->network_in_bytes ?? 0,
                'network_out_bytes' => $metrics?->network_out_bytes ?? 0,
                'sampled_at' => $metrics?->created_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a manual backup
     */
    public function createBackup(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $backupService = new ContainerBackupService();
            $backup = $backupService->createBackup($service, 'manual');

            return response()->json([
                'id' => $backup->id,
                'backup_name' => $backup->backup_name,
                'status' => $backup->status,
                'size_bytes' => $backup->size_bytes,
                'created_at' => $backup->created_at->toIso8601String(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * List all backups for a service
     */
    public function listBackups(Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        try {
            $backupService = new ContainerBackupService();
            $backups = $backupService->listServiceBackups($service);

            return response()->json([
                'data' => $backups->map(fn ($b) => [
                    'id' => $b->id,
                    'backup_name' => $b->backup_name,
                    'status' => $b->status,
                    'type' => $b->type,
                    'size_bytes' => $b->size_bytes,
                    'completed_at' => $b->completed_at?->toIso8601String(),
                    'created_at' => $b->created_at->toIso8601String(),
                ])->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restore from a backup
     */
    public function restoreBackup(Service $service, ContainerBackup $backup): JsonResponse
    {
        $this->authorize('view', $service);

        if ($backup->service_id !== $service->id) {
            return response()->json(['error' => 'Backup does not belong to this service'], 403);
        }

        try {
            $backupService = new ContainerBackupService();
            $backupService->restoreBackup($backup);

            return response()->json(['message' => 'Container restored from backup']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a backup
     */
    public function deleteBackup(Service $service, ContainerBackup $backup): JsonResponse
    {
        $this->authorize('view', $service);

        if ($backup->service_id !== $service->id) {
            return response()->json(['error' => 'Backup does not belong to this service'], 403);
        }

        try {
            $backupService = new ContainerBackupService();
            $backupService->deleteBackup($backup);

            return response()->json(['message' => 'Backup deleted']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
