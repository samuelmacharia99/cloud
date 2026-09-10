<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHCommandException;

/**
 * What a failed container command actually said, for a customer to read.
 *
 * Three kinds of text arrive mixed together on one channel. The plumbing: the
 * SSH wrapper, host paths, and Compose narrating every container it touched.
 * The tool's own epilogue: npm's error block, which repeats the exit code and
 * the path and says nothing about the cause. And, somewhere in the middle, the
 * program's own message, which is the only part worth reading.
 *
 * The summary drops the first two so the last one survives a short line.
 * Nothing is dropped from the full output except secrets and host paths, since
 * a panel with room should show everything.
 */
class ContainerCommandOutputPresenter
{
    public function summary(string $raw, int $limit = 300): string
    {
        $lines = $this->lines($raw);

        // Prefer the program's own words. An npm epilogue is seven lines long,
        // so keeping it would spend the whole line on the exit code.
        $spoken = array_values(array_filter($lines, fn (string $line): bool => ! $this->isToolNoise($line)));
        $chosen = $spoken !== [] ? $spoken : $lines;

        $detail = trim(preg_replace('/\s+/', ' ', implode(' ', array_slice($chosen, -6))) ?? '');

        return $detail === '' ? '' : $this->tailWithin($detail, $limit);
    }

    public function full(string $raw, int $limit = 4000): string
    {
        return $this->tailWithin(implode("\n", $this->lines($raw)), $limit);
    }

    /**
     * @return list<string>
     */
    public function lines(string $raw): array
    {
        $useful = [];

        foreach (preg_split('/\R/', SSHCommandException::redactSensitive($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'SSH command failed')) {
                continue;
            }
            // The exit status alone says nothing the program's own words do not.
            if (str_starts_with($line, 'Error: Command exited with status')) {
                continue;
            }

            // Only the wrapper's own label is removed. "Error:" is left alone:
            // it is how Node and Python start a real message, and cutting it
            // turned "Error: connect ECONNREFUSED" into "connect ECONNREFUSED".
            if (str_starts_with($line, 'Output: ')) {
                $line = substr($line, strlen('Output: '));
            }

            if (str_contains($line, ContainerDeploymentService::CONTAINER_BASE_PATH)
                || preg_match('/\b(?:PGPASSWORD|MYSQL_PWD)=/', $line) === 1
                || $this->isPlumbing($line)) {
                continue;
            }

            $useful[] = ltrim($line, '-');
        }

        return $useful;
    }

    /**
     * Compose's own narration: the docker CLI's warning log, and the progress
     * it prints for every resource while bringing a stack up.
     */
    public function isPlumbing(string $line): bool
    {
        return preg_match('/^time="[^"]*"\s+level=/', $line) === 1
            || preg_match(
                '/^(?:Container|Network|Volume|Image)\s+\S+\s+'
                .'(?:Running|Created|Creating|Started|Starting|Recreated|Recreating|Healthy|Waiting|Existing|Pulling|Pulled|Built|Building|Removed|Removing|Stopped|Stopping)\b/',
                $line
            ) === 1;
    }

    /**
     * A package manager's closing report. It names the exit code, the path and
     * the command, all of which the panel already shows.
     */
    public function isToolNoise(string $line): bool
    {
        return preg_match('/^(?:npm|yarn|pnpm)\s+(?:error|ERR!|notice|warn|WARN)\b/i', $line) === 1;
    }

    private function tailWithin(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit
            ? $text
            : '…'.mb_substr($text, -($limit - 1));
    }
}
