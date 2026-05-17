<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Node;
use Illuminate\Http\JsonResponse;

class NodeApiController
{
    /**
     * List all nodes (admin only)
     */
    public function index(): JsonResponse
    {
        $nodes = Node::where('type', 'container_host')
            ->select('id', 'name', 'ip_address', 'type', 'is_active', 'container_count', 'max_containers')
            ->get();

        return response()->json([
            'data' => $nodes->map(fn ($n) => [
                'id' => $n->id,
                'name' => $n->name,
                'ip_address' => $n->ip_address,
                'type' => $n->type,
                'is_active' => $n->is_active,
                'container_count' => $n->container_count ?? 0,
                'max_containers' => $n->max_containers ?? 100,
                'utilization' => round(($n->container_count ?? 0) / ($n->max_containers ?? 100) * 100, 2),
            ])->toArray(),
        ]);
    }

    /**
     * Get a specific node (admin only)
     */
    public function show(Node $node): JsonResponse
    {
        if ($node->type !== 'container_host') {
            return response()->json(['error' => 'Node is not a container host'], 404);
        }

        $deployments = $node->containerDeployments()
            ->with('service')
            ->get();

        return response()->json([
            'id' => $node->id,
            'name' => $node->name,
            'ip_address' => $node->ip_address,
            'type' => $node->type,
            'is_active' => $node->is_active,
            'container_count' => $node->container_count ?? 0,
            'max_containers' => $node->max_containers ?? 100,
            'utilization' => round(($node->container_count ?? 0) / ($node->max_containers ?? 100) * 100, 2),
            'deployments' => $deployments->map(fn ($d) => [
                'id' => $d->id,
                'service_id' => $d->service_id,
                'service_name' => $d->service->name,
                'container_name' => $d->container_name,
                'status' => $d->status,
                'port' => $d->assigned_port,
            ])->toArray(),
        ]);
    }
}
