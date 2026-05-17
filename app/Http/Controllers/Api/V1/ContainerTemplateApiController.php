<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContainerTemplate;
use Illuminate\Http\JsonResponse;

class ContainerTemplateApiController
{
    /**
     * List all container templates
     */
    public function index(): JsonResponse
    {
        $templates = ContainerTemplate::with('products')
            ->select('id', 'slug', 'name', 'description', 'docker_image', 'category', 'required_cpu_cores', 'required_ram_mb')
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'description' => $t->description,
                'docker_image' => $t->docker_image,
                'category' => $t->category,
                'required_cpu_cores' => $t->required_cpu_cores,
                'required_ram_mb' => $t->required_ram_mb,
                'products_count' => $t->products->count(),
            ])->toArray(),
        ]);
    }

    /**
     * Get a specific container template
     */
    public function show(ContainerTemplate $template): JsonResponse
    {
        $template->load('products', 'versions');

        $deploymentCount = \App\Models\ContainerDeployment::whereHas('service.product', function ($q) use ($template) {
            $q->where('container_template_id', $template->id);
        })->count();

        return response()->json([
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'description' => $template->description,
            'docker_image' => $template->docker_image,
            'default_port' => $template->default_port,
            'category' => $template->category,
            'required_cpu_cores' => $template->required_cpu_cores,
            'required_ram_mb' => $template->required_ram_mb,
            'required_storage_gb' => $template->required_storage_gb,
            'environment_variables' => $template->environment_variables ?? [],
            'volume_paths' => $template->volume_paths ?? [],
            'setup_commands' => $template->setup_commands ?? [],
            'products' => $template->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
            ])->toArray(),
            'deployments_count' => $deploymentCount,
        ]);
    }
}
