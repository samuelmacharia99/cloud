<?php

namespace App\Services\Provisioning;

/**
 * Rewrite a mysqldump file for the mysql client one statement at a time.
 *
 * The in-memory rewrite read the whole dump, split it, flattened every
 * statement and joined the result, holding several copies of the file in a
 * 128 MB PHP process; a WordPress dump of a few dozen megabytes killed the
 * convert with a fatal error. This scanner keeps the same statement boundary
 * rules (quotes, comments, DELIMITER) but reads line by line and writes each
 * flattened statement as soon as it ends, so memory stays at one statement.
 *
 * Output is identical to ContainerSqlDumpImportService::mysqlClientDump():
 * "SET NAMES utf8mb4" first, one statement per line, ";\n" after each. A
 * trigger, procedure, function or event carries semicolons inside its body,
 * so it is written between DELIMITER ;; and DELIMITER ; the way mysqldump
 * does; otherwise the mysql client splits it at the first inner semicolon
 * and MySQL answers "syntax error near ''".
 */
final class StreamingMysqlDumpRewriter
{
    /** Standalone statements that must not run inside a customer's sidecar. */
    private const DROP_PATTERNS = [
        '/^CREATE\s+DATABASE\b/i',
        '/^DROP\s+DATABASE\b/i',
        '/^DROP\s+SCHEMA\b/i',
        '/^CREATE\s+USER\b/i',
        '/^ALTER\s+USER\b/i',
        '/^GRANT\b/i',
        '/^REVOKE\b/i',
        '/^USE\s+[`\'"]?[A-Za-z0-9_\-]+[`\'"]?$/i',
    ];

    private const DEFINER_PATTERN = '/\sDEFINER=(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\')/i';

    public const COMPOUND_DELIMITER = ';;';

    /**
     * A statement whose body holds its own semicolons: the mysql client must
     * be told a different delimiter before it is sent.
     */
    public static function isCompoundStatement(string $flat): bool
    {
        if (! str_contains($flat, ';')) {
            return false;
        }

        return preg_match('/\bCREATE\b(?:(?!\bTABLE\b|\bINDEX\b|\bVIEW\b|\bDATABASE\b).){0,400}?\b(?:TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/is', $flat) === 1
            || preg_match('/^(?:\/\*!\d+\s*)?(?:ALTER|CREATE)\b.*\bBEGIN\b.*\bEND\b/is', $flat) === 1;
    }

    /**
     * One statement in the form the mysql client needs, terminated.
     */
    public static function clientLine(string $flat): string
    {
        if (self::isCompoundStatement($flat)) {
            return 'DELIMITER '.self::COMPOUND_DELIMITER."\n".$flat.self::COMPOUND_DELIMITER."\nDELIMITER ;\n";
        }

        return $flat.";\n";
    }

    /**
     * @param  callable(string): string  $flatten  puts one statement on one physical line
     */
    public function __construct(private $flatten) {}

    /**
     * @return int statements written (excluding the SET NAMES prelude)
     */
    public function rewrite(string $inputPath, string $outputPath): int
    {
        $in = @fopen($inputPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('SQL dump file is missing or empty.');
        }
        $out = @fopen($outputPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Could not rewrite the SQL dump for the MySQL client.');
        }

        $written = 0;
        $state = 'code';
        $delimiter = ';';
        $current = '';
        $sawContent = false;

        try {
            fwrite($out, "SET NAMES utf8mb4;\n");
            while (($line = fgets($in)) !== false) {
                if (! $sawContent && trim($line) !== '') {
                    $sawContent = true;
                }
                $line = str_replace(["\r\n", "\r"], "\n", $line);
                $this->scanLine($line, $state, $delimiter, $current, function (string $statement) use ($out, &$written): void {
                    if ($this->emit($out, $statement)) {
                        $written++;
                    }
                });
            }
            if ($this->emit($out, $current)) {
                $written++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if (! $sawContent) {
            throw new \RuntimeException('SQL dump file is missing or empty.');
        }
        if ($written === 0) {
            throw new \RuntimeException('SQL dump contained no executable statements after cleanup.');
        }

        return $written;
    }

    /**
     * @param  resource  $out
     */
    private function emit($out, string $statement): bool
    {
        $trimmed = trim($statement);
        if ($trimmed === '' || preg_match('/^DELIMITER\b/i', $trimmed) === 1) {
            return false;
        }

        $flat = ($this->flatten)($trimmed);
        $flat = preg_replace(self::DEFINER_PATTERN, '', $flat) ?? $flat;
        $flat = trim($flat);
        if ($flat === '') {
            return false;
        }
        foreach (self::DROP_PATTERNS as $pattern) {
            if (preg_match($pattern, $flat) === 1) {
                return false;
            }
        }

        fwrite($out, self::clientLine($flat));

        return true;
    }

    /**
     * Same state machine as ContainerSqlDumpImportService::splitSqlStatements,
     * carried across lines. Two-character tokens never start with a newline,
     * so looking ahead only within the line loses nothing.
     *
     * @param  callable(string): void  $onStatement
     */
    private function scanLine(string $line, string &$state, string &$delimiter, string &$current, callable $onStatement): void
    {
        $length = strlen($line);
        $i = 0;

        while ($i < $length) {
            $ch = $line[$i];
            $next = $i + 1 < $length ? $line[$i + 1] : '';

            if ($state === 'code') {
                if ($this->startsDelimiterKeyword($line, $i)) {
                    $onStatement($current);
                    $current = '';
                    $i += 9;
                    while ($i < $length && ctype_space($line[$i])) {
                        $i++;
                    }
                    $new = '';
                    while ($i < $length && $line[$i] !== "\n") {
                        $new .= $line[$i];
                        $i++;
                    }
                    $delimiter = trim($new) !== '' ? trim($new) : ';';
                    if ($i < $length && $line[$i] === "\n") {
                        $i++;
                    }

                    continue;
                }

                if ($ch === '#' || ($ch === '-' && $next === '-')) {
                    $state = 'linecomment';
                    $current .= $ch;
                    $i++;

                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $state = 'blockcomment';
                    $current .= '/*';
                    $i += 2;

                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') {
                    $state = match ($ch) {
                        "'" => 'single',
                        '"' => 'double',
                        default => 'backtick',
                    };
                    $current .= $ch;
                    $i++;

                    continue;
                }

                $delimLen = strlen($delimiter);
                if ($delimLen > 0 && substr($line, $i, $delimLen) === $delimiter) {
                    $onStatement($current);
                    $current = '';
                    $i += $delimLen;

                    continue;
                }

                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'single' || $state === 'double') {
                $quote = $state === 'single' ? "'" : '"';
                if ($ch === '\\') {
                    $current .= $ch.$next;
                    $i += $next === '' ? 1 : 2;

                    continue;
                }
                if ($ch === $quote && $next === $quote) {
                    $current .= $quote.$quote;
                    $i += 2;

                    continue;
                }
                if ($ch === $quote) {
                    $state = 'code';
                }
                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'backtick') {
                if ($ch === '`' && $next === '`') {
                    $current .= '``';
                    $i += 2;

                    continue;
                }
                if ($ch === '`') {
                    $state = 'code';
                }
                $current .= $ch;
                $i++;

                continue;
            }

            if ($state === 'linecomment') {
                $current .= $ch;
                if ($ch === "\n") {
                    $state = 'code';
                }
                $i++;

                continue;
            }

            // block comment
            $current .= $ch;
            if ($ch === '*' && $next === '/') {
                $current .= $next;
                $i += 2;
                $state = 'code';

                continue;
            }
            $i++;
        }
    }

    private function startsDelimiterKeyword(string $line, int $i): bool
    {
        if (strncasecmp(substr($line, $i, 9), 'DELIMITER', 9) !== 0) {
            return false;
        }
        // At a line start the previous character was the newline that ended the last line.
        if ($i > 0 && ! ctype_space($line[$i - 1])) {
            return false;
        }
        $after = $line[$i + 9] ?? ' ';

        return $after === '' || ctype_space($after);
    }
}
