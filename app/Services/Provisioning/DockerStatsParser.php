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

        // Batched collectors include the container name as the first column.
        if (count($parts) >= 5 && ! str_contains($parts[0], '%')) {
            $parts = array_slice($parts, 1);
        }

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

    /**
     * Parse multi-line `docker stats` output that includes {{.Name}} as the first column.
     *
     * @return array<string, array{cpu: string, mem: string, net: string, block: string}>
     */
    public static function parseNamedLines(string $output): array
    {
        $result = [];

        foreach (preg_split("/\r\n|\n|\r/", trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split("/\t+/", $line) ?: [];
            if (count($parts) < 5) {
                continue;
            }

            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }

            try {
                $result[$name] = self::parseLine(implode("\t", array_slice($parts, 1)), $name);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $result;
    }

    public static function parseMemoryToMb(string $value): int
    {
        $value = strtoupper(trim($value));
        $amount = (float) $value;

        return match (true) {
            str_contains($value, 'TIB'), str_contains($value, 'TB') => (int) ($amount * 1024 * 1024),
            str_contains($value, 'GIB'), str_contains($value, 'GB') => (int) ($amount * 1024),
            str_contains($value, 'MIB'), str_contains($value, 'MB') => (int) $amount,
            str_contains($value, 'KIB'), str_contains($value, 'KB') => (int) ($amount / 1024),
            str_contains($value, 'B') => (int) ($amount / 1024 / 1024),
            default => (int) $amount,
        };
    }

    public static function parseDataToBytes(string $value): int
    {
        $value = strtoupper(trim($value));
        $amount = (float) $value;

        // Prefer binary units (MiB/GiB) before SI-looking suffixes (MB/GB), because
        // Docker mixes both depending on metric and engine version.
        return match (true) {
            str_contains($value, 'TIB') => (int) ($amount * 1024 ** 4),
            str_contains($value, 'GIB') => (int) ($amount * 1024 ** 3),
            str_contains($value, 'MIB') => (int) ($amount * 1024 ** 2),
            str_contains($value, 'KIB') => (int) ($amount * 1024),
            str_contains($value, 'TB') => (int) ($amount * 1000 ** 4),
            str_contains($value, 'GB') => (int) ($amount * 1000 ** 3),
            str_contains($value, 'MB') => (int) ($amount * 1000 ** 2),
            str_contains($value, 'KB') => (int) ($amount * 1000),
            str_contains($value, 'B') => (int) $amount,
            default => (int) $amount,
        };
    }

    public static function clampCpuPercentage(float $cpuPercent): float
    {
        // container_metrics.cpu_percentage is decimal(5,2).
        return max(0, min(999.99, round($cpuPercent, 2)));
    }
}
