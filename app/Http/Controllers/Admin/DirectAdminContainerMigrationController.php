<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\MigrateDirectAdminWordPressToContainerJob;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectAdminContainerMigrationController extends Controller
{
    public function show(Service $service, DirectAdminToContainerMigrationService $migrator): View|RedirectResponse
    {
        if (! $service->isSharedHosting()) {
            return redirect()->route('admin.services.show', $service)
                ->withErrors(['error' => 'Only DirectAdmin shared hosting can migrate to app hosting.']);
        }

        try {
            $inventory = $migrator->inventory($service);
            $inventoryError = null;
        } catch (\Throwable $e) {
            $inventory = null;
            $inventoryError = $e->getMessage();
        }

        $targets = $migrator->availableWordPressTargets($service);

        return view('admin.services.migrate-to-container', compact('service', 'inventory', 'inventoryError', 'targets'));
    }

    public function store(Request $request, Service $service, DirectAdminToContainerMigrationService $migrator): RedirectResponse
    {
        $validated = $request->validate([
            'target_service_id' => 'required|exists:services,id',
            'database_name' => 'nullable|string|max:64',
        ]);

        $target = Service::with('product.containerTemplate', 'containerDeployment')->findOrFail($validated['target_service_id']);

        try {
            $migrator->assertCanMigrate($service, $target);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $meta = is_array($target->service_meta) ? $target->service_meta : [];
        $meta['da_migration'] = [
            'status' => 'queued',
            'source_service_id' => $service->id,
            'queued_at' => now()->toIso8601String(),
        ];
        $target->update(['service_meta' => $meta]);

        MigrateDirectAdminWordPressToContainerJob::dispatch(
            $service->id,
            $target->id,
            $validated['database_name'] ?? null,
        );

        return redirect()
            ->route('admin.services.show', $target)
            ->with('success', 'WordPress → container migration queued.');
    }
}
