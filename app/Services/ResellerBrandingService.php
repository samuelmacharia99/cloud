<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class ResellerBrandingService
{
    public function uploadFile(User $reseller, UploadedFile $file, string $type): array
    {
        if (!in_array($type, ['logo', 'favicon'])) {
            throw new Exception("Invalid file type: {$type}");
        }

        try {
            $settings = $reseller->settings ?? [];

            // Delete old file if exists
            $oldPath = $settings['branding']["{$type}_path"] ?? null;
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
                Log::info("Deleted old {$type} for reseller", [
                    'reseller_id' => $reseller->id,
                    'path' => $oldPath,
                ]);
            }

            // Generate filename with original extension
            $extension = $file->getClientOriginalExtension();
            $filename = "{$type}.{$extension}";
            $path = "branding/resellers/{$reseller->id}/{$filename}";

            // Store new file
            $storagePath = $file->storeAs(
                "branding/resellers/{$reseller->id}",
                $filename,
                'public'
            );

            $url = branding_asset_url(Storage::disk('public')->url($storagePath))
                ?? '/storage/'.$storagePath;

            // Update settings
            if (!isset($settings['branding'])) {
                $settings['branding'] = [];
            }
            $settings['branding']["{$type}_url"] = $url;
            $settings['branding']["{$type}_path"] = $storagePath;
            $settings['branding']['updated_at'] = now()->toIso8601String();

            $reseller->update(['settings' => $settings]);

            Log::info("Uploaded {$type} for reseller", [
                'reseller_id' => $reseller->id,
                'path' => $storagePath,
                'url' => $url,
            ]);

            return [
                'url' => $url,
                'path' => $storagePath,
            ];
        } catch (Exception $e) {
            Log::error("Failed to upload {$type} for reseller", [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteFile(User $reseller, string $type): void
    {
        if (!in_array($type, ['logo', 'favicon'])) {
            throw new Exception("Invalid file type: {$type}");
        }

        try {
            $settings = $reseller->settings ?? [];

            // Delete file from storage
            $path = $settings['branding']["{$type}_path"] ?? null;
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                Log::info("Deleted {$type} for reseller", [
                    'reseller_id' => $reseller->id,
                    'path' => $path,
                ]);
            }

            // Clear settings
            if (isset($settings['branding'])) {
                unset($settings['branding']["{$type}_url"]);
                unset($settings['branding']["{$type}_path"]);
                $settings['branding']['updated_at'] = now()->toIso8601String();
            }

            $reseller->update(['settings' => $settings]);
        } catch (Exception $e) {
            Log::error("Failed to delete {$type} for reseller", [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
