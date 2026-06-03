<?php

namespace App\Services\Provisioning;

/**
 * Parses `docker stats --no-stream` tab-separated format output.
 */
class DockerStatsParser
{
    /**
     * @return array{cpu: string, mem: string, net: string, block: string}
     */
    public static function parseLine(string $output, string $containerName): array
    {
        $line = trim($output);
        if ($line === '' || $line === '{}') {
            throw new \InvalidArgumentException("Failed to parse docker stats for {$containerName}");
        }

        // Legacy JSON line from older collectors
        if (str_starts_with($line, '{')) {
            $data = json_decode($line, true);
            if (is_array($data) && ! empty($data['cpu']) && $data['cpu'] !== '--') {
                return [
                    'cpu' => (string) $data['cpu'],
                    'mem' => (string) ($data['mem'] ?? '0 / 0'),
                    'net' => (string) ($data['net'] ?? '0 / 0'),
                    'block' => (string) ($data['block'] ?? '0 / 0'),
                ];
            }

            throw new \InvalidArgumentException("Failed to parse docker stats for {$containerName}");
        }

        $parts = preg_split("/\t+/", $line) ?: [];
        if (count($parts) < 4) {
            throw new \InvalidArgumentException("Failed to parse docker stats for {$containerName}");
        }

        $cpu = trim($parts[0]);
        if ($cpu === '' || $cpu === '--') {
            throw new \InvalidArgumentException("Failed to parse docker stats for {$containerName}");
        }

        return [
            'cpu' => $cpu,
            'mem' => trim($parts[1]),
            'net' => trim($parts[2]),
            'block' => trim($parts[3]),
        ];
    }

    public static function parseMemoryToMb(string $value): int
    {
        $value = strtoupper(trim($value));

        if (str_contains($value, 'GIB')) {
            return (int) ((float) $value * 1024);
        }
        if (str_contains($value, 'MIB')) {
            return (int) (float) $value;
        }
        if (str_contains($value, 'KIB')) {
            return (int) ((float) $value / 1024);
        }
        if (str_contains($value, 'B')) {
            return (int) ((float) $value / 1024 / 1024);
        }

        return (int) (float) $value;
    }

    public static function parseDataToBytes(string $value): int
    {
        $value = strtoupper(trim($value));

        if (str_contains($value, 'GB')) {
            return (int) ((float) $value * 1024 * 1024 * 1024);
        }
        if (str_contains($value, 'MB')) {
            return (int) ((float) $value * 1024 * 1024);
        }
        if (str_contains($value, 'KB')) {
            return (int) ((float) $value * 1024);
        }
        if (str_contains($value, 'B')) {
            return (int) (float) $value;
        }

        return (int) (float) $value;
    }
}
