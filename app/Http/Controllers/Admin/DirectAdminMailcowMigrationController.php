<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RetryDirectAdminMailPullJob;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Illuminate\Http\JsonResponse;
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

    public function status(Service $service, DirectAdminMailPullProgress $progress): JsonResponse
    {
        return response()->json($progress->operatorView($service));
    }

    public function retry(
        Request $request,
        Service $service,
        DirectAdminMailPullProgress $progress,
    ): JsonResponse|RedirectResponse {
        $legacy = is_array($service->service_meta['da_legacy'] ?? null) ? $service->service_meta['da_legacy'] : [];
        $migration = is_array($service->service_meta['mailcow_migration'] ?? null) ? $service->service_meta['mailcow_migration'] : [];
        if ($legacy === [] && $migration === [] && ! $service->isSharedHosting()) {
            return $this->retryResponse($request, $service, false, 'This service has no DirectAdmin mail pull to retry.');
        }

        if ($progress->isActive($service)) {
            return $this->retryResponse($request, $service, true, 'Mail pull is already running. Watch the live terminal.');
        }

        $progress->queue($service);
        RetryDirectAdminMailPullJob::dispatch($service->id)->afterResponse();

        return $this->retryResponse(
            $request,
            $service,
            true,
            'Mail pull queued. Watch the live terminal for percent and copy progress.',
        );
    }

    private function retryResponse(
        Request $request,
        Service $service,
        bool $ok,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->wantsJson() || $request->ajax()) {
            $payload = app(DirectAdminMailPullProgress::class)->operatorView($service);
            $payload['success'] = $ok;
            $payload['message'] = $message;

            return response()->json($payload, $ok ? 200 : 422);
        }

        if (! $ok) {
            return redirect()->route('admin.services.show', $service)
                ->withErrors(['error' => $message]);
        }

        return redirect()->route('admin.services.show', $service)
            ->with('success', $message);
    }
}
