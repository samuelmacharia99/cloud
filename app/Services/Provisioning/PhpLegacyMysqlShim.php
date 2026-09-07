<?php

namespace App\Services\Provisioning;

use App\Services\SSH\SSHService;

/**
 * DirectAdmin PHP 5/7 apps call mysql_* which PHP 8 removed. That fatals with
 * an empty HTTP 500 because php-fpm has display_errors=off and often log_errors=off.
 */
class PhpLegacyMysqlShim
{
    public const RELATIVE_PATH = '.talksasa-mysql-shim.php';

    public function script(): string
    {
        return <<<'PHP'
<?php
if (function_exists('mysql_connect')) {
    return;
}

function talksasa_mysql_link($link = null) {
    if ($link instanceof mysqli) {
        return $link;
    }
    return $GLOBALS['__talksasa_mysql'] ?? null;
}

function mysql_connect($host = null, $user = null, $password = null, $new_link = false, $client_flags = 0) {
    $host = $host ?: ini_get('mysqli.default_host');
    $user = $user ?: ini_get('mysqli.default_user');
    $password = $password ?: ini_get('mysqli.default_pw');
    $mysqli = @new mysqli($host, (string) $user, (string) $password);
    if ($mysqli->connect_errno) {
        $GLOBALS['__talksasa_mysql_error'] = $mysqli->connect_error;
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    $GLOBALS['__talksasa_mysql'] = $mysqli;
    $GLOBALS['__talksasa_mysql_error'] = '';

    return $mysqli;
}

function mysql_pconnect($host = null, $user = null, $password = null, $client_flags = 0) {
    return mysql_connect($host, $user, $password, false, $client_flags);
}

function mysql_select_db($database, $link = null) {
    $mysqli = talksasa_mysql_link($link);
    if (! $mysqli instanceof mysqli) {
        return false;
    }

    return $mysqli->select_db($database);
}

function mysql_query($query, $link = null) {
    $mysqli = talksasa_mysql_link($link);
    if (! $mysqli instanceof mysqli) {
        return false;
    }
    $result = $mysqli->query($query);
    $GLOBALS['__talksasa_mysql_error'] = $mysqli->error;

    return $result;
}

function mysql_unbuffered_query($query, $link = null) {
    $mysqli = talksasa_mysql_link($link);
    if (! $mysqli instanceof mysqli) {
        return false;
    }

    return $mysqli->query($query, MYSQLI_USE_RESULT);
}

function mysql_real_escape_string($string, $link = null) {
    $mysqli = talksasa_mysql_link($link);
    if ($mysqli instanceof mysqli) {
        return $mysqli->real_escape_string((string) $string);
    }

    return addslashes((string) $string);
}

function mysql_escape_string($string) {
    return mysql_real_escape_string($string);
}

function mysql_fetch_array($result, $type = 3) {
    if (! $result instanceof mysqli_result) {
        return false;
    }
    $mode = match ((int) $type) {
        1 => MYSQLI_ASSOC,
        2 => MYSQLI_NUM,
        default => MYSQLI_BOTH,
    };

    return $result->fetch_array($mode);
}

function mysql_result($result, $row, $field = 0) {
    if (! $result instanceof mysqli_result) {
        return false;
    }
    $result->data_seek((int) $row);
    $data = $result->fetch_array(MYSQLI_BOTH);

    return is_array($data) ? ($data[$field] ?? false) : false;
}

function mysql_set_charset($charset, $link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->set_charset((string) $charset) : false;
}

function mysql_ping($link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->ping() : false;
}

function mysql_get_server_info($link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->server_info : '';
}

function mysql_fetch_assoc($result) {
    return $result instanceof mysqli_result ? $result->fetch_assoc() : false;
}

function mysql_fetch_row($result) {
    return $result instanceof mysqli_result ? $result->fetch_row() : false;
}

function mysql_fetch_object($result, $class = 'stdClass', $params = []) {
    return $result instanceof mysqli_result ? $result->fetch_object($class, $params) : false;
}

function mysql_num_rows($result) {
    return $result instanceof mysqli_result ? $result->num_rows : 0;
}

function mysql_num_fields($result) {
    return $result instanceof mysqli_result ? $result->field_count : 0;
}

function mysql_affected_rows($link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->affected_rows : -1;
}

function mysql_insert_id($link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->insert_id : 0;
}

function mysql_error($link = null) {
    $mysqli = talksasa_mysql_link($link);
    if ($mysqli instanceof mysqli && $mysqli->error !== '') {
        return $mysqli->error;
    }

    return (string) ($GLOBALS['__talksasa_mysql_error'] ?? '');
}

function mysql_errno($link = null) {
    $mysqli = talksasa_mysql_link($link);

    return $mysqli instanceof mysqli ? $mysqli->errno : 0;
}

function mysql_close($link = null) {
    $mysqli = talksasa_mysql_link($link);
    if ($mysqli instanceof mysqli) {
        $mysqli->close();
        if (($GLOBALS['__talksasa_mysql'] ?? null) === $mysqli) {
            unset($GLOBALS['__talksasa_mysql']);
        }
    }

    return true;
}

function mysql_free_result($result) {
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return true;
}

function mysql_data_seek($result, $offset) {
    return $result instanceof mysqli_result ? $result->data_seek((int) $offset) : false;
}

if (! defined('MYSQL_ASSOC')) {
    define('MYSQL_ASSOC', 1);
}
if (! defined('MYSQL_NUM')) {
    define('MYSQL_NUM', 2);
}
if (! defined('MYSQL_BOTH')) {
    define('MYSQL_BOTH', 3);
}
PHP;
    }

    public function usesRemovedMysqlExtension(string $source): bool
    {
        return preg_match('/\bmysql_(connect|pconnect|query|select_db|fetch_|num_rows|error|real_escape_string)\s*\(/i', $source) === 1
            && ! str_contains($source, 'talksasa_mysql_link');
    }

    public function userIniContents(): string
    {
        return 'auto_prepend_file=/app/'.self::RELATIVE_PATH."\n";
    }

    /**
     * @return list<string> Host paths written
     */
    public function installOnHost(SSHService $ssh, string $hostAppPath): array
    {
        $written = [];
        $shimHost = rtrim($hostAppPath, '/').'/'.self::RELATIVE_PATH;
        $ssh->upload($this->script(), $shimHost);
        $written[] = $shimHost;

        foreach (['', '/public', '/public_html'] as $relative) {
            $dir = rtrim($hostAppPath, '/').$relative;
            try {
                $exists = trim($ssh->exec('test -d '.escapeshellarg($dir).' && echo yes || echo no', 10));
            } catch (\Throwable) {
                continue;
            }
            if ($exists !== 'yes') {
                continue;
            }
            $ini = $dir.'/.user.ini';
            $ssh->upload($this->userIniContents(), $ini);
            $written[] = $ini;
        }

        return $written;
    }
}
