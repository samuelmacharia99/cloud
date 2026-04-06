<?php

namespace App\Http\Controllers\Admin;

use App\Models\ContainerTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ContainerTemplateController
{
    /**
     * Display list of container templates
     */
    public function index(): View
    {
        $templates = ContainerTemplate::with('products')
            ->orderBy('order')
            ->get();

        return view('admin.container-templates.index', compact('templates'));
    }

    /**
     * Show create template form
     */
    public function create(): View
    {
        return view('admin.container-templates.create');
    }

    /**
     * Store new container template
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|unique:container_templates,slug',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|in:web,database,utility,cache',
            'docker_image' => 'required|string',
            'default_port' => 'required|integer|min:1|max:65535',
            'required_ram_mb' => 'required|integer|min:64|max:65536',
            'required_cpu_cores' => 'required|numeric|min:0.1|max:16',
            'required_storage_gb' => 'required|integer|min:1|max:1024',
            'environment_variables' => 'nullable|string',
            'volume_paths' => 'nullable|string',
            'compose_services' => 'nullable|string',
            'setup_commands' => 'nullable|string',
            'is_active' => 'boolean',
            'order' => 'required|integer|min:0',
        ]);

        // Parse JSON fields
        $envVars = [];
        if ($request->filled('environment_variables')) {
            $envVars = json_decode($request->input('environment_variables'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['environment_variables' => 'Invalid JSON format']);
            }
        }

        $volumePaths = [];
        if ($request->filled('volume_paths')) {
            $volumePaths = json_decode($request->input('volume_paths'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['volume_paths' => 'Invalid JSON format']);
            }
        }

        $composeServices = [];
        if ($request->filled('compose_services')) {
            $composeServices = json_decode($request->input('compose_services'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['compose_services' => 'Invalid JSON format']);
            }
        }

        $setupCommands = [];
        if ($request->filled('setup_commands')) {
            $setupCommands = json_decode($request->input('setup_commands'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['setup_commands' => 'Invalid JSON format']);
            }
        }

        ContainerTemplate::create([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'category' => $validated['category'],
            'docker_image' => $validated['docker_image'],
            'default_port' => $validated['default_port'],
            'required_ram_mb' => $validated['required_ram_mb'],
            'required_cpu_cores' => $validated['required_cpu_cores'],
            'required_storage_gb' => $validated['required_storage_gb'],
            'environment_variables' => $envVars ?: null,
            'volume_paths' => $volumePaths ?: null,
            'compose_services' => $composeServices ?: null,
            'setup_commands' => $setupCommands ?: null,
            'is_active' => $request->boolean('is_active'),
            'order' => $validated['order'],
        ]);

        return redirect()->route('admin.container-templates.index')
            ->with('success', 'Container template created successfully');
    }

    /**
     * Show edit template form
     */
    public function edit(ContainerTemplate $containerTemplate): View
    {
        return view('admin.container-templates.edit', compact('containerTemplate'));
    }

    /**
     * Update container template
     */
    public function update(Request $request, ContainerTemplate $containerTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|unique:container_templates,slug,' . $containerTemplate->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|in:web,database,utility,cache',
            'docker_image' => 'required|string',
            'default_port' => 'required|integer|min:1|max:65535',
            'required_ram_mb' => 'required|integer|min:64|max:65536',
            'required_cpu_cores' => 'required|numeric|min:0.1|max:16',
            'required_storage_gb' => 'required|integer|min:1|max:1024',
            'environment_variables' => 'nullable|string',
            'volume_paths' => 'nullable|string',
            'compose_services' => 'nullable|string',
            'setup_commands' => 'nullable|string',
            'is_active' => 'boolean',
            'order' => 'required|integer|min:0',
        ]);

        // Parse JSON fields
        $envVars = [];
        if ($request->filled('environment_variables')) {
            $envVars = json_decode($request->input('environment_variables'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['environment_variables' => 'Invalid JSON format']);
            }
        }

        $volumePaths = [];
        if ($request->filled('volume_paths')) {
            $volumePaths = json_decode($request->input('volume_paths'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['volume_paths' => 'Invalid JSON format']);
            }
        }

        $composeServices = [];
        if ($request->filled('compose_services')) {
            $composeServices = json_decode($request->input('compose_services'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['compose_services' => 'Invalid JSON format']);
            }
        }

        $setupCommands = [];
        if ($request->filled('setup_commands')) {
            $setupCommands = json_decode($request->input('setup_commands'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['setup_commands' => 'Invalid JSON format']);
            }
        }

        $containerTemplate->update([
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'category' => $validated['category'],
            'docker_image' => $validated['docker_image'],
            'default_port' => $validated['default_port'],
            'required_ram_mb' => $validated['required_ram_mb'],
            'required_cpu_cores' => $validated['required_cpu_cores'],
            'required_storage_gb' => $validated['required_storage_gb'],
            'environment_variables' => $envVars ?: null,
            'volume_paths' => $volumePaths ?: null,
            'compose_services' => $composeServices ?: null,
            'setup_commands' => $setupCommands ?: null,
            'is_active' => $request->boolean('is_active'),
            'order' => $validated['order'],
        ]);

        return redirect()->route('admin.container-templates.index')
            ->with('success', 'Container template updated successfully');
    }

    /**
     * Delete container template
     */
    public function destroy(ContainerTemplate $containerTemplate): RedirectResponse
    {
        if ($containerTemplate->products()->exists()) {
            return back()->withErrors(['error' => 'Cannot delete template with active products']);
        }

        $containerTemplate->delete();

        return redirect()->route('admin.container-templates.index')
            ->with('success', 'Container template deleted successfully');
    }
}
