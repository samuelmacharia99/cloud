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

        $blank = $this->settingsSetToNothing($output);
        if ($blank !== []) {
            return [
                'message' => 'The application could not start because '
                    .(count($blank) === 1 ? 'this setting is' : 'these settings are')
                    .' present but empty, and the application needs a real value: '.implode(', ', $blank)
                    .'. Give each one a value under Environment on this service, or delete the row entirely '
                    .'if the application has a default for it. An empty box is not the same as an absent setting.',
                // Reported as missing, because to the customer they are: an
                // empty box and no box at all are the same request. Reporting
                // them holds the site on a notice naming them, which beats a
                // crash loop the visitor sees as a broken site.
                'missing_variables' => $blank,
                'unparsable_variables' => $blank,
            ];
        }

        $wrongType = $this->settingsOfTheWrongType($output);
        if ($wrongType !== []) {
            return [
                'message' => 'The application could not start because '
                    .(count($wrongType) === 1 ? 'this setting holds' : 'these settings hold')
                    .' a value of the wrong kind: '.implode(', ', $wrongType)
                    .'. A setting the application reads as a number wants digits only, and one it reads as '
                    .'true or false wants true or false. Correct the value under Environment on this service.',
                'missing_variables' => [],
                'unparsable_variables' => $wrongType,
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

        $ownRules = $this->applicationRejectedItsOwnConfiguration($output);
        if ($ownRules !== []) {
            return [
                'message' => 'The application refused to start because its own configuration check failed: '
                    .implode(' ', $ownRules)
                    .' That sentence is written in your code, so it names a rule your settings do not satisfy. '
                    .'Correct the values under Environment on this service, then start it again.',
                'missing_variables' => [],
                'unparsable_variables' => [],
            ];
        }

        $syncDriver = $this->synchronousDriverOnAsyncEngine($output);
        if ($syncDriver !== null) {
            return [
                'message' => 'The application opens an asynchronous database connection, but its database URL '
                    ."named the synchronous {$syncDriver} driver, so it refused to start. The platform writes that "
                    .'URL, and it now names an async driver whenever your dependency list ships one. Pull again to '
                    .'pick up the corrected URL. If it still fails, add an async driver to the requirements file in '
                    .'your application root: asyncpg for PostgreSQL, asyncmy or aiomysql for MySQL.',
                // Nothing here is the customer's to type. The name involved is
                // DATABASE_URL, which the platform owns, so reporting it as a
                // missing or unreadable setting would send somebody to the
                // Environment tab to fix a row they cannot edit.
                'missing_variables' => [],
                'unparsable_variables' => [],
            ];
        }

        $schema = $this->databaseSchemaIsMissing($output);
        if ($schema !== null) {
            return [
                'message' => 'The application started, but its database does not have the schema its code expects'
                    .$schema.'. Migrations have not run against this database, or they have not caught up with '
                    .'the code that was just pulled. Pull again to run them, or run them from the Database tab '
                    .'of this service.',
                // The customer has no variable to set here. The credentials are
                // right, the connection works, and the tables are absent.
                'missing_variables' => [],
                'unparsable_variables' => [],
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
     * A database the application can reach but whose tables are not there.
     *
     * This is what an unmigrated stack looks like from the outside: the
     * container is up, the credentials are right, the connection succeeds, and
     * every query fails. The application usually keeps running, which is
     * exactly why nothing here used to notice it.
     *
     *     sqlalchemy.exc.ProgrammingError: (psycopg.errors.UndefinedTable)
     *     relation "users" does not exist
     *
     * @return string|null a phrase naming the relation, or an empty string when
     *                     the log only gives the error class
     */
    private function databaseSchemaIsMissing(string $output): ?string
    {
        $recognised = '/\b(?:UndefinedTable|UndefinedColumn|ProgrammingError)\b'
            .'|relation "[^"]+" does not exist'
            .'|no such table'
            ."|Table '[^']+' doesn't exist/i";

        if (preg_match($recognised, $output) !== 1) {
            return null;
        }

        foreach ([
            '/relation "([^"]+)" does not exist/i',
            "/Table '(?:[^.']*\.)?([^.']+)' doesn't exist/i",
            '/no such table:? ([A-Za-z0-9_]+)/i',
        ] as $pattern) {
            if (preg_match($pattern, $output, $matches) === 1) {
                return ': the table "'.$matches[1].'" does not exist';
            }
        }

        return '';
    }

    /**
     * SQLAlchemy's async engine refusing the driver its URL named:
     *
     *     sqlalchemy.exc.InvalidRequestError: The asyncio extension requires an
     *     async driver to be used. The loaded 'psycopg2' is not async.
     *
     * Always the platform's fault rather than the customer's, because the
     * platform is what composes DATABASE_URL.
     *
     * @return string|null the synchronous driver that was loaded
     */
    private function synchronousDriverOnAsyncEngine(string $output): ?string
    {
        $pattern = "/asyncio extension requires an async driver.*?loaded '([A-Za-z0-9_]+)' is not async/is";

        if (preg_match($pattern, $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * A rule the application enforces on its own settings, which pydantic
     * reports without naming any field:
     *
     *     pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
     *       Value error, Production config must use the M-Pesa production environment
     *
     * A model-level validator has no field to blame, so every reader here that
     * looks for a field name followed by a message found nothing, and a
     * customer got fifty frames of uvicorn and click instead of the one
     * sentence their own code had written for exactly this moment.
     *
     * The message is passed through as the application wrote it. Nothing here
     * knows what the rule means, and paraphrasing somebody's own words is how
     * a diagnosis becomes a guess.
     *
     * @return list<string>
     */
    private function applicationRejectedItsOwnConfiguration(string $output): array
    {
        if (! str_contains($output, 'validation error')) {
            return [];
        }

        if (! preg_match_all('/^\s*Value error,\s*(.+)$/m', $output, $matches)) {
            return [];
        }

        $messages = [];
        foreach ($matches[1] as $message) {
            // pydantic appends "[type=value_error, input_value={...}]", and
            // input_value echoes the settings that were supplied. Keeping it
            // would print the customer's configuration into an alert.
            $message = (string) preg_replace('/\s*\[type=.*$/s', '', (string) $message);
            $message = trim($message);

            if ($message === '') {
                continue;
            }

            $messages[rtrim($message, '.').'.'] = true;
        }

        return array_keys($messages);
    }

    /**
     * A setting whose box exists and is empty.
     *
     * Worth its own sentence because it is the one shape a customer cannot see
     * from the outside. An absent setting and a setting present with an empty
     * string look identical in a settings list, and pydantic treats them
     * completely differently: the first falls back to a default, the second is
     * handed to the parser, which refuses it.
     *
     * @return list<string>
     */
    private function settingsSetToNothing(string $output): array
    {
        return $this->pydanticFieldsWhere(
            $output,
            fn (string $detail): bool => preg_match('/input_value=(\'\'|"")/', $detail) === 1,
        );
    }

    /**
     * A setting holding a value the declared type cannot accept, which pydantic
     * reports as bool_parsing, int_parsing, float_parsing and friends.
     *
     * @return list<string>
     */
    private function settingsOfTheWrongType(string $output): array
    {
        return $this->pydanticFieldsWhere(
            $output,
            fn (string $detail): bool => preg_match('/\[type=[a-z_]*(parsing|type)/i', $detail) === 1,
        );
    }

    /**
     * pydantic prints every validation error as a bare field name followed by
     * an indented sentence about it. Which sentence it is decides what to tell
     * the customer, so the pairing is read once and asked different questions.
     *
     * @param  callable(string): bool  $matches
     * @return list<string>
     */
    private function pydanticFieldsWhere(string $output, callable $matches): array
    {
        if (! str_contains($output, 'validation error')) {
            return [];
        }

        $lines = preg_split('/\R/', $output) ?: [];
        $fields = [];

        foreach ($lines as $index => $line) {
            $name = trim($line);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            $detail = $lines[$index + 1] ?? '';
            if (trim($detail) === '' || preg_match('/^\s+\S/', $detail) !== 1) {
                continue;
            }

            if ($matches($detail)) {
                $fields[$name] = true;
            }
        }

        return array_keys($fields);
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
