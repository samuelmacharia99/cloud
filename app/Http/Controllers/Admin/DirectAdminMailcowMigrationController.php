<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectAdminMailcowMigrationController extends Controller
{
    public function show(Service $service, DirectAdminToMailcowMigrationService $migrator): View|RedirectResponse
    {
        if (! $service->isSharedHosting()) {
            return redirect()->route('admin.services.show', $service)
                ->withErrors(['error' => 'Only DirectAdmin shared hosting can migrate mail to Mailcow.']);
        }

        $preflight = $migrator->preflight($service);

        return view('admin.services.migrate-mail-to-mailcow', [
            'service' => $service->load('product', 'node', 'user'),
            'preflight' => $preflight,
            'convertMeta' => $service->service_meta['mailcow_migration'] ?? null,
        ]);
    }

    public function store(Request $request, Service $service, DirectAdminToMailcowMigrationService $migrator): RedirectResponse
    {
        if (! $service->isSharedHosting()) {
            return redirect()->route('admin.services.show', $service)
                ->withErrors(['error' => 'Only DirectAdmin shared hosting can migrate mail.']);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'create_sync_jobs' => ['nullable', 'boolean'],
            'da_imap_host' => ['nullable', 'string', 'max:255'],
            'da_imap_password' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $migrator->migrate($service, [
            'product_id' => (int) $validated['product_id'],
            'create_sync_jobs' => $request->boolean('create_sync_jobs'),
            'da_imap_host' => $validated['da_imap_host'] ?? null,
            'da_imap_password' => $validated['da_imap_password'] ?? null,
        ]);

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']]);
        }

        $emailService = $result['email_service'] ?? null;

        return redirect()
            ->route('admin.services.show', $emailService ?? $service)
            ->with('success', $result['message']);
    }
}
