<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatabaseTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DatabaseTemplateController extends Controller
{
    public function index(): View
    {
        $templates = DatabaseTemplate::orderBy('order')->get();

        return view('admin.database-templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('admin.database-templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTemplate($request);
        DatabaseTemplate::create($validated);

        return redirect()
            ->route('admin.database-templates.index')
            ->with('success', 'Database template created successfully.');
    }

    public function edit(DatabaseTemplate $databaseTemplate): View
    {
        return view('admin.database-templates.edit', compact('databaseTemplate'));
    }

    public function update(Request $request, DatabaseTemplate $databaseTemplate): RedirectResponse
    {
        $validated = $this->validateTemplate($request, $databaseTemplate->id);
        $databaseTemplate->update($validated);

        return redirect()
            ->route('admin.database-templates.index')
            ->with('success', 'Database template updated successfully.');
    }

    public function destroy(DatabaseTemplate $databaseTemplate): RedirectResponse
    {
        $databaseTemplate->delete();

        return redirect()
            ->route('admin.database-templates.index')
            ->with('success', 'Database template deleted successfully.');
    }

    private function validateTemplate(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = 'required|string|max:255|unique:database_templates,slug';
        if ($ignoreId) {
            $slugRule .= ',' . $ignoreId;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => $slugRule,
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:mysql,mariadb,postgresql,mongodb,redis',
            'versions' => 'nullable|string',
            'docker_image' => 'required|string|max:255',
            'default_port' => 'required|integer|min:1|max:65535',
            'required_ram_mb' => 'required|integer|min:64|max:262144',
            'hosting_type' => 'required|in:container,directadmin',
            'is_active' => 'sometimes|boolean',
            'order' => 'nullable|integer|min:0|max:1000',
        ]);

        $versions = [];
        if ($request->filled('versions')) {
            $decoded = json_decode($request->input('versions'), true);
            if (!is_array($decoded)) {
                throw ValidationException::withMessages([
                    'versions' => 'Versions must be a valid JSON array (e.g. ["8.0","5.7"]).',
                ]);
            }
            $versions = array_values(array_filter($decoded, fn ($v) => is_string($v) && trim($v) !== ''));
        }

        $validated['versions'] = $versions;
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);
        $validated['order'] = (int) ($validated['order'] ?? 0);

        return $validated;
    }
}
