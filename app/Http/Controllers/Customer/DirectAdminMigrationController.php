<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Jobs\MigrateDirectAdminWordPressToContainerJob;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectAdminMigrationController extends Controller
{
    public function show(Service $service, DirectAdminToContainerMigrationService $migrator): View|RedirectResponse
    {
        $this->authorize('view', $service);

        if (! $service->isSharedHosting()) {
            return redirect()->route('customer.services.show', $service)
                ->with('error', 'Only shared hosting (DirectAdmin) services can use the app hosting migrator.');
        }

        try {
            $inventory = $migrator->inventory($service);
        } catch (\Throwable $e) {
            $inventory = null;
            $inventoryError = $e->getMessage();
        }

        $targets = $migrator->availableWordPressTargets($service);

        return view('customer.services.migrate-to-app', [
            'service' => $service->load('product', 'node'),
            'inventory' => $inventory ?? null,
            'inventoryError' => $inventoryError ?? null,
            'targets' => $targets,
        ]);
    }

    public function store(Request $request, Service $service, DirectAdminToContainerMigrationService $migrator): RedirectResponse
    {
        $this->authorize('view', $service);

        if (! $service->isSharedHosting()) {
            return back()->with('error', 'Invalid source service.');
        }

        $validated = $request->validate([
            'target_service_id' => 'required|exists:services,id',
            'database_name' => 'nullable|string|max:64',
            'confirm_email' => 'accepted',
        ]);

        $target = Service::with('product.containerTemplate', 'containerDeployment')->findOrFail($validated['target_service_id']);

        try {
            $migrator->assertCanMigrate($service, $target);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
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
        )->afterResponse();

        return redirect()
            ->route('customer.services.container.show', ['service' => $target, 'tab' => 'overview'])
            ->with('success', 'WordPress migration queued. Progress is tracked on the target app hosting service.');
    }
}
