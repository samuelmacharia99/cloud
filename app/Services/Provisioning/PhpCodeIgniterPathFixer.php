<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

/**
 * DirectAdmin often puts CodeIgniter 4's public/index.php at /app/index.php
 * while app/Config/Paths.php stays at /app/app/Config/Paths.php. The stock
 * require FCPATH.'../app/Config/Paths.php' then resolves to /app/Config/.
 */
class PhpCodeIgniterPathFixer
{
    public function looksLikeCodeIgniterFrontController(string $source): bool
    {
        return str_contains($source, 'Config/Paths.php')
            || str_contains($source, 'FCPATH')
            || str_contains($source, 'CodeIgniter');
    }

    public function needsFlattenedPathsRequire(string $source, bool $nestedAppPathsExist, bool $resolvedPathsExist): bool
    {
        return $nestedAppPathsExist
            && ! $resolvedPathsExist
            && $this->looksLikeCodeIgniterFrontController($source)
            && str_contains($source, '../app/Config/Paths.php');
    }

    public function rewriteFrontController(string $source): string
    {
        if (! str_contains($source, '../app/Config/Paths.php')) {
            return $source;
        }

        return str_replace('../app/Config/Paths.php', 'app/Config/Paths.php', $source);
    }

    /**
     * @return int Files rewritten
     */
    public function applyOnHost(SSHService $ssh, string $hostAppPath): int
    {
        $root = rtrim($hostAppPath, '/');
        $index = $root.'/index.php';
        $nested = $root.'/app/Config/Paths.php';
        $resolved = $root.'/Config/Paths.php';

        try {
            $hasIndex = trim($ssh->exec('test -f '.escapeshellarg($index).' && echo yes || echo no', 10));
            $hasNested = trim($ssh->exec('test -f '.escapeshellarg($nested).' && echo yes || echo no', 10));
            $hasResolved = trim($ssh->exec('test -f '.escapeshellarg($resolved).' && echo yes || echo no', 10));
        } catch (\Throwable) {
            return 0;
        }

        if ($hasIndex !== 'yes' || $hasNested !== 'yes') {
            return 0;
        }

        try {
            $original = $ssh->downloadFile($index);
        } catch (\Throwable) {
            return 0;
        }

        if (! $this->needsFlattenedPathsRequire($original, true, $hasResolved === 'yes')) {
            return 0;
        }

        $updated = $this->rewriteFrontController($original);
        if ($updated === $original) {
            return 0;
        }

        $ssh->upload($updated, $index);

        return 1;
    }
}
