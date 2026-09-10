<?php

namespace App\Services\Provisioning;

/**
 * Turn a crashed Python container's output into a cause the customer can act on.
 *
 * Python web apps normally build their settings object and import their routers
 * at module load, so a missing variable or an unimportable package kills uvicorn
 * with exit 1 before it binds a port. Docker then restarts it, readiness reports
 * only "not stably running", and the pip transcript pushes the one line that
 * names the cause out of every truncated alert.
 */
class PythonRuntimeErrorPresenter
{
    /**
     * Null when the output carries no recognisable Python start-up failure, so
     * the caller can fall back to the raw container log.
     *
     * @return array{message: string, missing_variables: list<string>}|null
     */
    public function present(string $output): ?array
    {
        if (trim($output) === '') {
            return null;
        }

        $missing = $this->missingSettingsFields($output);
        if ($missing !== []) {
            return [
                'message' => 'The application could not start because required environment variables are missing: '
                    .implode(', ', $missing)
                    .'. Add them under Environment on this service, then redeploy.',
                'missing_variables' => $missing,
            ];
        }

        $module = $this->unimportableModule($output);
        if ($module !== null) {
            return [
                'message' => "The application could not start because Python cannot import \"{$module}\". "
                    .'Check that the package lives inside the application root this container builds, '
                    .'and that every dependency it needs is listed in that root\'s requirements.txt.',
                'missing_variables' => [],
            ];
        }

        return null;
    }

    /**
     * pydantic-settings prints each unsatisfied field as its own bare line,
     * followed by an indented "Field required" line:
     *
     *     SECRET_KEY
     *       Field required [type=missing, input_value={...}]
     *
     * @return list<string>
     */
    private function missingSettingsFields(string $output): array
    {
        if (! str_contains($output, 'Field required')) {
            return [];
        }

        $lines = preg_split('/\R/', $output) ?: [];
        $fields = [];

        foreach ($lines as $index => $line) {
            $name = trim($line);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            if (! str_starts_with(trim($lines[$index + 1] ?? ''), 'Field required')) {
                continue;
            }

            // Keyed to de-duplicate while keeping the order pydantic reported.
            $fields[$name] = true;
        }

        return array_keys($fields);
    }

    private function unimportableModule(string $output): ?string
    {
        if (preg_match('/ModuleNotFoundError: No module named [\'"]([^\'"]+)[\'"]/', $output, $matches) === 1) {
            return $matches[1];
        }

        // Uvicorn reports its own entrypoint separately from a nested import failure.
        if (preg_match('/Could not import module [\'"]([^\'"]+)[\'"]/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
