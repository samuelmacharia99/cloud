<?php

namespace App\Services\Provisioning;

use Illuminate\Http\UploadedFile;

/**
 * Chunks the browser sends one request at a time, kept on the panel until
 * the last one arrives, then joined into a single file. Each part is freed
 * as it is appended so a large upload needs its own size on disk once, not
 * twice. Used by the SQL importer and the file manager.
 */
class ChunkedUploadStore
{
    /** What the browser slices uploads into; it must stay under PHP's 2 MB post limit. */
    public const CHUNK_BYTES = 1024 * 1024;

    /** A chunk may carry multipart overhead beyond CHUNK_BYTES. */
    public const CHUNK_MAX_KB = 2048;

    /**
     * How many CHUNK_BYTES slices the largest allowed upload takes, plus one
     * for the remainder.
     */
    public static function maxChunks(int $maxMb): int
    {
        return (int) ceil(($maxMb * 1024 * 1024) / self::CHUNK_BYTES) + 1;
    }

    /**
     * @return array{complete: bool, received: int, total: int, path?: string}
     */
    public function store(string $scope, int $serviceId, string $uploadId, int $index, int $total, UploadedFile $file): array
    {
        if ($total < 1 || $index < 0 || $index >= $total) {
            throw new \InvalidArgumentException('Invalid upload chunk.');
        }

        $dir = $this->directory($scope, $serviceId, $uploadId);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create a temporary directory for the upload.');
        }

        $file->move($dir, $index.'.part');
        if (! is_file($dir.'/'.$index.'.part')) {
            throw new \RuntimeException('Could not store upload chunk '.$index.'.');
        }

        $received = 0;
        for ($i = 0; $i < $total; $i++) {
            if (is_file($dir.'/'.$i.'.part')) {
                $received++;
            }
        }
        if ($received < $total) {
            return ['complete' => false, 'received' => $received, 'total' => $total];
        }

        $assembled = $dir.'/assembled';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not assemble the upload.');
        }
        try {
            for ($i = 0; $i < $total; $i++) {
                $part = $dir.'/'.$i.'.part';
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('Missing upload chunk '.$i.'.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                @unlink($part);
            }
        } finally {
            fclose($out);
        }

        return ['complete' => true, 'received' => $received, 'total' => $total, 'path' => $assembled];
    }

    public function forget(string $scope, int $serviceId, string $uploadId): void
    {
        $dir = $this->directory($scope, $serviceId, $uploadId);
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }

    public function directory(string $scope, int $serviceId, string $uploadId): string
    {
        $scope = preg_replace('/[^a-z0-9-]/', '', strtolower($scope)) ?: 'upload';
        $uploadId = preg_replace('/[^a-f0-9]/', '', strtolower($uploadId)) ?: 'x';

        return storage_path('app/chunked-uploads/'.$scope.'/'.$serviceId.'/'.$uploadId);
    }
}
