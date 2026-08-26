<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PurgeStorageBoxRetentionRequest;
use App\Services\Provisioning\InfrastructureStorageBoxService;
use App\Services\Provisioning\StorageBoxRetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StorageBoxController extends Controller
{
    public function show(string $storageBox, InfrastructureStorageBoxService $boxes): View
    {
        $box = $boxes->findOrFail($storageBox);

        return view('admin.storage-boxes.show', [
            'box' => $box,
        ]);
    }

    public function refreshStats(
        string $storageBox,
        InfrastructureStorageBoxService $boxes,
    ): RedirectResponse {
        $boxes->refreshDiskUsage($storageBox);

        return redirect()
            ->route('admin.storage-boxes.show', $storageBox)
            ->with('success', 'Storage Box capacity refreshed.');
    }

    public function purgeRetention(
        PurgeStorageBoxRetentionRequest $request,
        string $storageBox,
        InfrastructureStorageBoxService $boxes,
        StorageBoxRetentionService $retention,
    ): RedirectResponse {
        $boxes->findOrFail($storageBox);

        $days = (int) $request->validated('days');
        $result = $retention->purgeOlderThan($days, $request->user());

        $boxes->refreshDiskUsage($storageBox);

        if ($result['purged_count'] === 0 && $result['errors'] === []) {
            return redirect()
                ->route('admin.storage-boxes.show', $storageBox)
                ->with('success', "No completed backups were older than {$days} days.");
        }

        if ($result['errors'] !== []) {
            return redirect()
                ->route('admin.storage-boxes.show', $storageBox)
                ->with('error', "Purged {$result['purged_count']} archive(s), but ".count($result['errors']).' failed. Check admin activity for details.');
        }

        return redirect()
            ->route('admin.storage-boxes.show', $storageBox)
            ->with('success', 'Purged '.$result['purged_count'].' backup archive(s), freeing '.formatBytes((int) $result['freed_bytes']).'.');
    }
}
