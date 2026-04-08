<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

trait ValidatesFileUploads
{
    /**
     * Validate uploaded file against security rules.
     */
    protected function validateFileUpload(UploadedFile $file): void
    {
        $config = config('security.file_upload');

        // Check file size
        $maxSizeMb = $config['max_size_mb'] ?? 50;
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;

        if ($file->getSize() > $maxSizeBytes) {
            throw ValidationException::withMessages([
                'file' => "File size must not exceed {$maxSizeMb}MB",
            ]);
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = $config['allowed_extensions'] ?? [];

        if (!in_array($extension, $allowedExtensions)) {
            throw ValidationException::withMessages([
                'file' => "File type .{$extension} is not allowed",
            ]);
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!$this->isAllowedMimeType($mimeType)) {
            throw ValidationException::withMessages([
                'file' => 'Invalid file type detected',
            ]);
        }

        // Check for malicious content
        $this->scanForMaliciousContent($file);
    }

    /**
     * Check if MIME type is allowed.
     */
    private function isAllowedMimeType(string $mimeType): bool
    {
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
            'application/json',
        ];

        return in_array($mimeType, $allowedMimes);
    }

    /**
     * Scan file for malicious content.
     */
    private function scanForMaliciousContent(UploadedFile $file): void
    {
        if (!config('security.file_upload.scan_for_viruses', false)) {
            return;
        }

        // This would integrate with ClamAV or similar virus scanner
        // For now, we do basic content validation
        $content = file_get_contents($file->getRealPath());

        // Check for common malicious patterns
        $maliciousPatterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw ValidationException::withMessages([
                    'file' => 'File contains potentially malicious content',
                ]);
            }
        }
    }
}
