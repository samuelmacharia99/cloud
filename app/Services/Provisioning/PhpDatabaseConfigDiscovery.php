<?php

namespace App\Services\Provisioning;

/**
 * Where a plain PHP site keeps its MySQL credentials. DirectAdmin sites
 * rarely have a .env; they define DB_* constants, assign $dbhost-style
 * variables, call mysqli_connect() with literals, build a PDO DSN, or use a
 * CodeIgniter database array. Only string literals count: a value that is
 * itself a variable or a constant cannot be trusted without running the app.
 */
class PhpDatabaseConfigDiscovery
{
    private const NAME_KEYS = ['DB_NAME', 'DB_DATABASE', 'DATABASE_NAME', 'DBNAME', 'DB_DB', 'MYSQL_DATABASE', 'MYSQL_DB'];

    private const USER_KEYS = ['DB_USER', 'DB_USERNAME', 'DATABASE_USER', 'DBUSER', 'MYSQL_USER', 'DB_LOGIN'];

    private const PASS_KEYS = ['DB_PASSWORD', 'DB_PASS', 'DATABASE_PASSWORD', 'DBPASS', 'MYSQL_PASSWORD', 'DB_PWD', 'DB_PASSWD'];

    private const HOST_KEYS = ['DB_HOST', 'DB_SERVER', 'DATABASE_HOST', 'DBHOST', 'MYSQL_HOST', 'DB_HOSTNAME'];

    private const NAME_VARS = ['db_name', 'dbname', 'database', 'db_database', 'db', 'database_name', 'mysql_db', 'dbase'];

    private const USER_VARS = ['db_user', 'dbuser', 'username', 'user', 'db_username', 'database_user', 'mysql_user', 'db_login', 'dbusername'];

    private const PASS_VARS = ['db_pass', 'dbpass', 'password', 'pass', 'db_password', 'database_password', 'mysql_pass', 'passwd', 'dbpassword', 'db_pwd'];

    private const HOST_VARS = ['db_host', 'dbhost', 'host', 'servername', 'hostname', 'db_server', 'database_host', 'mysql_host', 'server', 'dbserver'];

    /**
     * Candidate files with only the lines that can carry credentials, each
     * block prefixed by "==> <path>".
     */
    public function command(string $docroot): string
    {
        $root = escapeshellarg(rtrim($docroot, '/'));
        $names = ['config*.php', '*config.php', '*Config.php', 'db*.php', 'database*.php', 'Database.php', 'conn*.php', 'settings*.php', 'constants*.php', 'init*.php', 'common.php', 'functions.php', 'connect*.php', 'mysql*.php', 'env.php', 'local.php', 'app.php', 'bootstrap.php', 'index.php'];
        $nameArgs = implode(' -o ', array_map(fn ($n) => '-iname '.escapeshellarg($n), $names));

        return 'root='.$root.'; [ -d "$root" ] || exit 0; '
            .'find "$root" -maxdepth 4 -type f \\( '.$nameArgs.' \\) -size -256k'
            .' ! -path "*/vendor/*" ! -path "*/node_modules/*" ! -path "*/wp-includes/*" ! -path "*/wp-admin/*" ! -path "*/cache/*" ! -path "*/.git/*" 2>/dev/null'
            .' | awk \'{ print length($0) "\\t" $0 }\' | sort -n | cut -f2- | head -n 60 | while IFS= read -r f; do '
            .'echo "==> $f"; grep -nE -A4 '.escapeshellarg('DB_|db_|dbname|dbuser|dbpass|dbhost|mysqli?_connect|new mysqli|new PDO|mysql:host=|hostname|username|password|database|servername').' "$f" 2>/dev/null | head -n 160; done; true';
    }

    /**
     * @return list<array{file: string, DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: ?string}>
     */
    public function parse(string $output, string $docroot = ''): array
    {
        $found = [];
        $current = null;
        $buffer = '';
        $flush = function () use (&$found, &$current, &$buffer): void {
            if ($current !== null) {
                $creds = $this->parseSource($buffer);
                if ($creds['DB_NAME'] !== null || $creds['DB_USER'] !== null) {
                    $found[] = ['file' => $current] + $creds;
                }
            }
            $buffer = '';
        };
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '==> ')) {
                $flush();
                $current = trim(substr($line, 4));

                continue;
            }
            // grep -n prefixes "12:" or "12-" (context); strip it.
            $buffer .= (string) preg_replace('/^\d+[:-]/', '', $line)."\n";
        }
        $flush();

        $root = rtrim($docroot, '/');
        usort($found, function ($a, $b) use ($root) {
            $score = function ($c) use ($root): int {
                $s = 0;
                if ($c['DB_NAME'] !== null) {
                    $s += 4;
                }
                if ($c['DB_USER'] !== null) {
                    $s += 3;
                }
                if ($c['DB_PASSWORD'] !== null) {
                    $s += 2;
                }
                $rel = $root !== '' && str_starts_with($c['file'], $root.'/') ? substr($c['file'], strlen($root) + 1) : $c['file'];
                $s -= min(3, substr_count($rel, '/'));
                if (preg_match('/(^|\/)(config|database|db|conn|connection|settings)[^\/]*\.php$/i', $rel) === 1) {
                    $s += 2;
                }

                return $s;
            };

            return $score($b) <=> $score($a);
        });

        return $found;
    }

    /**
     * @return array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: ?string}
     */
    public function parseSource(string $source): array
    {
        $creds = ['DB_NAME' => null, 'DB_USER' => null, 'DB_PASSWORD' => null, 'DB_HOST' => null];
        $lit = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")';
        $pick = fn (array $m, int $a, int $b) => isset($m[$a]) && $m[$a] !== '' ? stripcslashes($m[$a]) : (isset($m[$b]) && $m[$b] !== '' ? stripcslashes($m[$b]) : null);

        // define('KEY', 'value')
        if (preg_match_all('/define\s*\(\s*[\'"]([A-Z_]+)[\'"]\s*,\s*'.$lit.'\s*\)/i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $this->assign($creds, strtoupper($row[1]), $pick($row, 2, 3), self::NAME_KEYS, self::USER_KEYS, self::PASS_KEYS, self::HOST_KEYS);
            }
        }
        // $var = 'value'
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*'.$lit.'\s*;/', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $this->assign($creds, strtolower($row[1]), $pick($row, 2, 3), self::NAME_VARS, self::USER_VARS, self::PASS_VARS, self::HOST_VARS, true);
            }
        }
        // CodeIgniter 3: $db['default']['username'] = '...'; CodeIgniter 4 / arrays: 'username' => '...'
        if (preg_match_all('/\$db\s*\[\s*[\'"]default[\'"]\s*\]\s*\[\s*[\'"](hostname|username|password|database)[\'"]\s*\]\s*=\s*'.$lit.'/i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $this->assign($creds, strtolower($row[1]), $pick($row, 2, 3), ['database'], ['username'], ['password'], ['hostname'], true);
            }
        }
        if (preg_match_all('/[\'"](hostname|username|password|database|DBDriver)[\'"]\s*=>\s*'.$lit.'/i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $this->assign($creds, strtolower($row[1]), $pick($row, 2, 3), ['database'], ['username'], ['password'], ['hostname'], true);
            }
        }
        // mysqli_connect('host', 'user', 'pass', 'db') / new mysqli(...) / mysql_connect('host', 'user', 'pass')
        if (preg_match('/(?:mysqli_connect|new\s+mysqli|mysql_connect)\s*\(\s*'.$lit.'\s*,\s*'.$lit.'\s*,\s*'.$lit.'(?:\s*,\s*'.$lit.')?/i', $source, $m)) {
            $creds['DB_HOST'] ??= $pick($m, 1, 2);
            $creds['DB_USER'] ??= $pick($m, 3, 4);
            $creds['DB_PASSWORD'] ??= $pick($m, 5, 6);
            $creds['DB_NAME'] ??= $pick($m, 7, 8);
        }
        // new PDO("mysql:host=h;dbname=d", 'user', 'pass')
        if (preg_match('/new\s+PDO\s*\(\s*[\'"]mysql:([^\'"]*)[\'"]\s*,\s*'.$lit.'\s*,\s*'.$lit.'/i', $source, $m)) {
            if (preg_match('/host=([^;]+)/i', $m[1], $h)) {
                $creds['DB_HOST'] ??= trim($h[1]);
            }
            if (preg_match('/dbname=([^;]+)/i', $m[1], $d)) {
                $creds['DB_NAME'] ??= trim($d[1]);
            }
            $creds['DB_USER'] ??= $pick($m, 2, 3);
            $creds['DB_PASSWORD'] ??= $pick($m, 4, 5);
        } elseif (preg_match('/[\'"]mysql:([^\'"]*dbname=[^\'"]*)[\'"]/i', $source, $m)) {
            if (preg_match('/host=([^;]+)/i', $m[1], $h)) {
                $creds['DB_HOST'] ??= trim($h[1]);
            }
            if (preg_match('/dbname=([^;]+)/i', $m[1], $d)) {
                $creds['DB_NAME'] ??= trim($d[1]);
            }
        }

        foreach ($creds as $k => $v) {
            if ($v !== null && ($v === '' || str_contains($v, '$'))) {
                $creds[$k] = null;
            }
        }
        if ($creds['DB_NAME'] !== null && preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $creds['DB_NAME']) !== 1) {
            $creds['DB_NAME'] = null;
        }

        return $creds;
    }

    /**
     * The credentials to use, preferring a hit whose database is one DirectAdmin knows.
     *
     * @param  list<array{file: string, DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: ?string}>  $found
     * @param  list<string>  $daDatabases
     * @return array{file: string, DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: ?string}|null
     */
    public function choose(array $found, array $daDatabases): ?array
    {
        if ($found === []) {
            return null;
        }
        $known = array_map('strtolower', $daDatabases);
        foreach ($found as $hit) {
            if ($hit['DB_NAME'] !== null && in_array(strtolower($hit['DB_NAME']), $known, true)) {
                return $hit;
            }
        }
        foreach ($found as $hit) {
            if ($hit['DB_NAME'] !== null && $hit['DB_USER'] !== null) {
                return $hit;
            }
        }

        return $found[0];
    }

    /**
     * @param  array{DB_NAME: ?string, DB_USER: ?string, DB_PASSWORD: ?string, DB_HOST: ?string}  $creds
     * @param  list<string>  $names
     * @param  list<string>  $users
     * @param  list<string>  $passes
     * @param  list<string>  $hosts
     */
    private function assign(array &$creds, string $key, ?string $value, array $names, array $users, array $passes, array $hosts, bool $lower = false): void
    {
        if ($value === null) {
            return;
        }
        $key = $lower ? strtolower($key) : $key;
        if (in_array($key, $names, true)) {
            $creds['DB_NAME'] ??= $value;
        } elseif (in_array($key, $users, true)) {
            $creds['DB_USER'] ??= $value;
        } elseif (in_array($key, $passes, true)) {
            $creds['DB_PASSWORD'] ??= $value;
        } elseif (in_array($key, $hosts, true)) {
            $creds['DB_HOST'] ??= $value;
        }
    }
}
