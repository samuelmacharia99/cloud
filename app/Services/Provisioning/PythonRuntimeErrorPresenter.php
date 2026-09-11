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
     * @return array{message: string, missing_variables: list<string>, unparsable_variables: list<string>}|null
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
                'unparsable_variables' => [],
            ];
        }

        $unparsable = $this->unparsableSettings($output);
        if ($unparsable !== []) {
            return [
                'message' => 'The application could not start because '
                    .(count($unparsable) === 1 ? 'this setting is' : 'these settings are')
                    .' set to a value it cannot read: '.implode(', ', $unparsable)
                    .'. A setting the application treats as a list or an object has to be written as JSON, '
                    .'so a list of origins is ["https://one.example","https://two.example"] rather than '
                    .'one,two, and a blank is not a valid list. Correct the value under Environment on this '
                    .'service, apply it, then pull again.',
                // Deliberately not reported as missing. These names already hold
                // a value, so parking the stack would show the customer a setup
                // notice listing nothing: the hold lists what is unset, and
                // every one of these is set. Wrongly, but set.
                'missing_variables' => [],
                'unparsable_variables' => $unparsable,
            ];
        }

        $module = $this->unimportableModule($output);
        if ($module !== null) {
            return [
                'message' => "The application could not start because Python cannot import \"{$module}\". "
                    .'Check that the package lives inside the application root this container builds, '
                    .'and that every dependency it needs is listed in that root\'s requirements.txt.',
                'missing_variables' => [],
                'unparsable_variables' => [],
            ];
        }

        return null;
    }

    /**
     * A setting that is present and unreadable, which pydantic reports in a
     * different exception from the one it raises for an absent setting:
     *
     *     pydantic_settings.exceptions.SettingsError: error parsing value for
     *     field "ALLOWED_ORIGINS" from source "EnvSettingsSource"
     *
     * pydantic parses anything it treats as a list or an object as JSON, so a
     * comma-separated list of origins, or an empty string, fails here while a
     * plain string setting beside it is fine. The traceback is fifteen frames
     * of importlib with the cause on the last line, and nothing in it says the
     * word "environment", so the customer sees a library crash rather than
     * their own value.
     *
     * @return list<string>
     */
    private function unparsableSettings(string $output): array
    {
        if (! preg_match_all(
            '/error parsing value for field\s+["\']([^"\']+)["\']/i',
            $output,
            $matches,
        )) {
            return [];
        }

        $fields = [];
        foreach ($matches[1] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $fields[$name] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * The names an application says it is missing, whichever way it says it.
     *
     * @return list<string>
     */
    private function missingSettingsFields(string $output): array
    {
        $fields = $this->pydanticRequiredFields($output);
        if ($fields !== []) {
            return $fields;
        }

        $fields = $this->namedMissingConfiguration($output);
        if ($fields !== []) {
            return $fields;
        }

        $fields = $this->djangoImproperlyConfigured($output);
        if ($fields !== []) {
            return $fields;
        }

        return $this->environmentKeyError($output);
    }

    /**
     * An application that checks its own configuration reports every absent
     * name in one sentence rather than as pydantic fields:
     *
     *     Value error, Missing required production config: MPESA_SHORTCODE, NES_API_KEY [type=value_error, input_value={...}]
     *
     * Unreadable before, because it contains no "Field required" and the
     * traceback around it says nothing about configuration at all.
     *
     * @return list<string>
     */
    private function namedMissingConfiguration(string $output): array
    {
        $fields = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (stripos($line, 'missing') === false) {
                continue;
            }

            // pydantic appends "[type=..., input_value={...}]", and input_value
            // echoes the settings that WERE supplied. Reading past this point
            // would report the values the customer already set as missing ones.
            $line = (string) preg_replace('/\s*\[type=.*$/s', '', $line);

            $separator = strrpos($line, ':');
            if ($separator === false) {
                continue;
            }

            foreach (explode(',', substr($line, $separator + 1)) as $candidate) {
                $name = trim($candidate, " \t\n\r\0\x0B.\"'");
                if ($this->looksLikeEnvironmentName($name)) {
                    $fields[$name] = true;
                }
            }
        }

        return array_keys($fields);
    }

    /**
     * Django's way of saying the same thing:
     *
     *     ImproperlyConfigured: Set the SECRET_KEY environment variable
     *
     * The name is embedded in a sentence rather than listed, so it is read as a
     * token. An underscore is required and the sentence has to be about a
     * setting, which keeps acronyms such as WSGI out of the answer.
     *
     * @return list<string>
     */
    private function djangoImproperlyConfigured(string $output): array
    {
        if (! preg_match('/ImproperlyConfigured:(.*)$/mi', $output, $matches)) {
            return [];
        }

        $message = trim($matches[1]);
        if (stripos($message, 'environment') === false && stripos($message, 'setting') === false) {
            return [];
        }

        if (! preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b/', $message, $names)) {
            return [];
        }

        return array_values(array_unique($names[0]));
    }

    /**
     * A bare lookup on the environment, which raises KeyError with the name.
     *
     * Guarded on the traceback actually touching the environment: a KeyError on
     * an ordinary dictionary is a bug in the application, not a missing setting,
     * and telling a customer to go and add it would waste their afternoon.
     *
     * @return list<string>
     */
    private function environmentKeyError(string $output): array
    {
        if (! preg_match('/\b(?:os\.environ|getenv|environ\[)/i', $output)) {
            return [];
        }

        if (! preg_match_all('/KeyError:\s*[\'"]([^\'"]+)[\'"]/', $output, $matches)) {
            return [];
        }

        $fields = [];
        foreach ($matches[1] as $name) {
            $name = trim((string) $name);
            if ($this->looksLikeEnvironmentName($name)) {
                $fields[$name] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * Conservative on purpose. Naming something that is not a variable sends a
     * customer looking for a setting that does not exist.
     */
    private function looksLikeEnvironmentName(string $name): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $name) === 1;
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
    private function pydanticRequiredFields(string $output): array
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
