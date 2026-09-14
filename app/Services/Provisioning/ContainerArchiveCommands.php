<?php

namespace App\Services\Provisioning;

/**
 * Shell command builders for archives on a container host.
 *
 * Nodes have tar and python3 but neither unzip nor zip, so zip files are
 * handled by Python's zipfile module and tarballs by GNU tar. Every builder
 * returns a string for SSHService::execWithStatus(); the scripts print one
 * coded line (OK / UNSAFE / SYMLINK / TOO_LARGE / BAD_ARCHIVE) that
 * ContainerFileService turns into an operator sentence.
 */
final class ContainerArchiveCommands
{
    public const KIND_ZIP = 'zip';

    public const KIND_TAR = 'tar';

    public const KIND_TGZ = 'tgz';

    private const HEREDOC = 'TALKSASA_PY';

    /**
     * Archive kind from a file name, or null when the name is not an archive we extract.
     */
    public static function kind(string $name): ?string
    {
        $lower = strtolower(trim($name));

        return match (true) {
            str_ends_with($lower, '.zip') => self::KIND_ZIP,
            str_ends_with($lower, '.tar.gz'), str_ends_with($lower, '.tgz') => self::KIND_TGZ,
            str_ends_with($lower, '.tar') => self::KIND_TAR,
            default => null,
        };
    }

    public static function pythonAvailable(): string
    {
        return 'command -v python3 >/dev/null 2>&1 && echo yes || echo no';
    }

    /**
     * List and validate an archive without writing anything.
     */
    public static function inspect(string $archive, string $kind, int $capBytes): string
    {
        return $kind === self::KIND_ZIP
            ? self::zipCommand('inspect', $archive, '/nonexistent', $capBytes)
            : self::tarInspect($archive, $kind, $capBytes);
    }

    /**
     * Validate then extract into $dest (created if missing).
     */
    public static function extract(string $archive, string $kind, string $dest, int $capBytes): string
    {
        if ($kind === self::KIND_ZIP) {
            return 'mkdir -p '.escapeshellarg($dest).' && '.self::zipCommand('extract', $archive, $dest, $capBytes);
        }

        $flag = $kind === self::KIND_TGZ ? '-xzf' : '-xf';

        return self::tarInspect($archive, $kind, $capBytes)
            .' && mkdir -p '.escapeshellarg($dest)
            .' && tar '.$flag.' '.escapeshellarg($archive).' -C '.escapeshellarg($dest).' --no-same-owner';
    }

    /**
     * Pack the given paths (relative to $baseDir) into a zip with Python.
     *
     * @param  list<string>  $relPaths
     */
    public static function buildZip(string $baseDir, array $relPaths, string $outFile): string
    {
        $args = implode(' ', array_map('escapeshellarg', array_values($relPaths)));

        return 'python3 - '.escapeshellarg($outFile).' '.escapeshellarg($baseDir).' '.$args
            ." <<'".self::HEREDOC."'\n".self::buildZipScript()."\n".self::HEREDOC."\n";
    }

    /**
     * Pack the given paths (relative to $baseDir) into a tar.gz, tolerating
     * GNU tar's exit 1 for files that changed while being read.
     *
     * @param  list<string>  $relPaths
     */
    public static function buildTarGz(string $baseDir, array $relPaths, string $outFile): string
    {
        $args = implode(' ', array_map('escapeshellarg', array_values($relPaths)));

        return 'tar -czf '.escapeshellarg($outFile).' -C '.escapeshellarg($baseDir).' '.$args
            .' ; status=$?; if [ "$status" -eq 1 ] && [ -s '.escapeshellarg($outFile).' ]; then status=0; fi; exit "$status"';
    }

    /**
     * Total bytes and entry count under the given absolute paths.
     *
     * @param  list<string>  $absPaths
     */
    public static function measure(array $absPaths): string
    {
        $args = implode(' ', array_map('escapeshellarg', array_values($absPaths)));

        return 'du -sb '.$args.' 2>/dev/null | awk \'{ total += $1 } END { print total + 0 }\'';
    }

    public static function chownTree(string $path, string $owner): string
    {
        return 'chown -R '.escapeshellarg($owner).' '.escapeshellarg($path);
    }

    /**
     * Delete files in a directory older than the given number of minutes.
     */
    public static function pruneOlderThan(string $dir, int $minutes): string
    {
        return '[ -d '.escapeshellarg($dir).' ] && find '.escapeshellarg($dir)
            .' -mindepth 1 -maxdepth 1 -mmin +'.max(1, $minutes).' -exec rm -rf {} + 2>/dev/null; true';
    }

    private static function zipCommand(string $mode, string $archive, string $dest, int $capBytes): string
    {
        return 'python3 - '.escapeshellarg($mode).' '.escapeshellarg($archive).' '.escapeshellarg($dest).' '.max(0, $capBytes)
            ." <<'".self::HEREDOC."'\n".self::zipScript()."\n".self::HEREDOC."\n";
    }

    private static function tarInspect(string $archive, string $kind, int $capBytes): string
    {
        $flag = $kind === self::KIND_TGZ ? '-tvzf' : '-tvf';

        $awk = <<<'AWK'
BEGIN { total = 0; count = 0 }
{
    t = substr($1, 1, 1)
    name = $6
    for (i = 7; i <= NF; i++) { name = name " " $i }
    if (t == "l" || t == "h") { print "SYMLINK " name; exit 3 }
    if (name ~ /^\//) { print "UNSAFE " name; exit 3 }
    n = split(name, seg, "/")
    for (i = 1; i <= n; i++) { if (seg[i] == "..") { print "UNSAFE " name; exit 3 } }
    if (t == "-") { total += $3; count++ }
    if (total > cap) { print "TOO_LARGE " total; exit 4 }
}
END { print "OK " total " " count }
AWK;

        return 'set -o pipefail; tar '.$flag.' '.escapeshellarg($archive)
            .' | awk -v cap='.max(0, $capBytes).' '.escapeshellarg($awk);
    }

    /**
     * Inspect or extract a zip: rejects absolute names, ".." segments,
     * backslashes and symlink entries; caps the declared uncompressed size.
     */
    public static function zipScript(): string
    {
        return <<<'PY'
import sys, zipfile
mode, src, dest, cap = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
try:
    z = zipfile.ZipFile(src)
except Exception as e:
    print('BAD_ARCHIVE ' + str(e))
    sys.exit(2)
total = 0
count = 0
with z:
    for info in z.infolist():
        name = info.filename
        if name.startswith('/') or '\\' in name or '..' in name.split('/'):
            print('UNSAFE ' + name)
            sys.exit(3)
        if ((info.external_attr >> 16) & 0o170000) == 0o120000:
            print('SYMLINK ' + name)
            sys.exit(3)
        if not info.is_dir():
            total += info.file_size
            count += 1
        if total > cap:
            print('TOO_LARGE %d' % total)
            sys.exit(4)
    if mode == 'extract':
        z.extractall(dest)
print('OK %d %d' % (total, count))
PY;
    }

    /**
     * Zip the given relative paths under a base directory, skipping symlinks.
     */
    public static function buildZipScript(): string
    {
        return <<<'PY'
import sys, os, zipfile
out, base = sys.argv[1], sys.argv[2]
rels = sys.argv[3:]
count = 0
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as z:
    for rel in rels:
        p = os.path.join(base, rel)
        if os.path.islink(p):
            continue
        if os.path.isdir(p):
            z.write(p, rel)
            for root, dirs, files in os.walk(p):
                dirs[:] = [d for d in dirs if not os.path.islink(os.path.join(root, d))]
                for d in dirs:
                    full = os.path.join(root, d)
                    z.write(full, os.path.relpath(full, base))
                for f in files:
                    full = os.path.join(root, f)
                    if os.path.islink(full):
                        continue
                    z.write(full, os.path.relpath(full, base))
                    count += 1
        elif os.path.isfile(p):
            z.write(p, rel)
            count += 1
print('OK %d' % count)
PY;
    }
}
