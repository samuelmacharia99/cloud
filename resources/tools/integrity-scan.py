#!/usr/bin/env python3
"""Talksasa integrity scan for a customer application directory.

Runs on the container host over the bind mount, read-only. Prints one line per
hit as  reason<TAB>size<TAB>mtime<TAB>path  and a final  __SUMMARY__<TAB>{json}
line. Contents of files are never printed.

TALKSASA_SCAN_VERSION=3
"""
import argparse
import hashlib
import json
import math
import os
import re
import stat
import sys
import time

SCAN_VERSION = 3

CORE_ROOT_FILES = {
    'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config.php',
    'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
    'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
}
KNOWN_DROPINS = {
    'index.php', 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'sunrise.php',
    'maintenance.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php',
    'fatal-error-handler.php', 'php-error.php',
}
PHP_EXT = ('.php', '.phtml', '.phar', '.pht', '.inc', '.suspected', '.php3', '.php4', '.php5', '.php7', '.php8', '.phps')
IMAGE_LIKE_EXT = ('.ico', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.txt', '.webp', '.bmp')
NO_EXEC_DIRS = ('wp-content/uploads', 'wp-content/languages', 'wp-content/upgrade', 'wp-content/cache',
                'storage/app/public', 'public/uploads')
SKIP_CONTENT_DIRS = ('vendor', 'node_modules', 'wp-content/talksasa-disabled', 'wp-content/plugins-disabled', '.git')
KNOWN_FAMILY_FILES = {'wp-homes.php', 'wp-conf1g.php', 'wp-l0gin.php', 'wp-includes.php', 'wp-admins.php',
                      'wp-vcd.php', 'wp-tmp.php', 'wp-feml.php', 'class.theme-modules.php', 'radio.php',
                      'lock360.php', 'about.php.suspected'}
KNOWN_FAMILY_DIRS = {'alfacgiapi', 'jancox', 'ALFA_DATA', '.well-known-pki', 'wso', 'c99'}
EXPOSED_EXACT = {'.env', 'error_log', 'debug.log', 'php_errorlog', 'php_error.log', '.bash_history', '.mysql_history',
                 'wp-config.php.bak', 'wp-config.php.old', 'wp-config.php.orig', 'wp-config.php.save', 'wp-config.php~',
                 'wp-config.bak', 'wp-config.old', 'wp-config.txt', 'wp-config.php.txt', 'wp-config.php.swp'}
EXPOSED_SUFFIXES = ('.sql', '.sql.gz', '.sql.zip', '.bak', '.orig', '.old', '.swp', '.tar.gz', '.tgz', '.rar', '.7z', '.zip', '.tar')
NAME_ALLOW = {
    'index', 'admin', 'load', 'error', 'about', 'config', 'setup', 'debug', 'cache', 'class', 'plugin', 'theme',
    'widgets', 'media', 'users', 'edit', 'post', 'link', 'menu', 'ajax', 'cron', 'feed', 'rss', 'atom', 'update',
    'upgrade', 'install', 'import', 'export', 'options', 'tools', 'themes', 'plugins', 'comment', 'terms', 'upload',
    'moderation', 'privacy', 'revision', 'network', 'customize', 'credits', 'freedoms', 'site', 'user', 'ms', 'l10n',
    'kses', 'http', 'query', 'rewrite', 'script', 'shortcodes', 'taxonomy', 'template', 'vars', 'version',
    'canonical', 'capabilities', 'category', 'compat', 'deprecated', 'embed', 'formatting', 'functions', 'general',
    'locale', 'meta', 'nav', 'pluggable', 'registration', 'rest', 'robots', 'session', 'sitemaps', 'style', 'blocks',
    'fonts', 'html', 'https', 'interactivity', 'json', 'pomo', 'random', 'speculative', 'text', 'wp', 'getid3',
    'smtp', 'pop3', 'oauth', 'exception', 'phpmailer', 'autoload', 'bookmark', 'misc', 'noop', 'schema', 'screen',
    'term', 'file', 'image', 'dashboard', 'profile', 'settings', 'sites', 'xmlrpc', 'utf8', 'mo', 'po', 'diff',
    'helpers', 'loader', 'main', 'init', 'bootstrap', 'common', 'core', 'api', 'app', 'lib', 'util', 'utils',
    'base', 'model', 'view', 'ctrl', 'hooks', 'filters', 'actions', 'shortcode', 'widget', 'sidebar', 'footer',
    'header', 'single', 'page', 'archive', 'search', 'author', 'date', 'tag', 'home', 'front', 'blog', 'news',
    'contact', 'gallery', 'portfolio', 'slider', 'form', 'forms', 'mail', 'email', 'login', 'logout', 'register',
    'account', 'checkout', 'cart', 'shop', 'product', 'products', 'order', 'orders', 'payment', 'thanks',
    'legacy', 'compat', 'polyfill', 'polyfills', 'uninstall', 'activate', 'deactivate', 'ajax', 'cli', 'cron',
    'db', 'sql', 'mysql', 'redis', 'memcache', 'apc', 'pdf', 'csv', 'xml', 'rss2', 'sitemap', 'amp', 'seo', 'cdn',
    'ssl', 'smtp', 'imap', 'ftp', 'sftp', 'ssh', 'gzip', 'minify', 'lazyload', 'webp', 'avif', 'svg', 'icons',
}

SIGNATURES = [
    r'eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|urldecode|rawurldecode)\s*\(',
    r'preg_replace\s*\(\s*["\'][^"\']*/[a-zA-Z]*e[a-zA-Z]*["\']',
    r'\b(system|passthru|shell_exec|popen|proc_open|exec|pcntl_exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b',
    r'\bassert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\b',
    r'\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)',
    r'\b(FilesMan|alfacgiapi|c99sh|r57shell|b374k|IndoXploit|WSO\s?[0-9]|Web\s?Shell\s?by|shell_by|priv8|0byt3|Sh3ll|bypass\s*shell|uname -a)\b',
    r'eval\s*\(\s*["\'][A-Za-z0-9+/=]{200,}',
    r'move_uploaded_file\s*\(\s*\$_FILES\s*\[[^\]]+\]\s*\[\s*["\']tmp_name["\']\s*\]\s*,\s*\$_(GET|POST|REQUEST)',
    r'\$\{\s*["\']_(GET|POST|REQUEST|COOKIE)["\']\s*\}',
    r'\$\{\s*["\']GLOBALS["\']\s*\}\s*\[',
    r'(chr\(\d+\)\s*\.\s*){15,}',
    r'\bcreate_function\s*\(\s*["\']["\']\s*,\s*(base64_decode|gzinflate|str_rot13|\$_)',
    r'\bcall_user_func(_array)?\s*\(\s*(base64_decode|str_rot13|strrev|\$_(GET|POST|REQUEST|COOKIE))',
    r'\b(fwrite|file_put_contents)\s*\(\s*[^,]+,\s*(base64_decode|gzinflate)\s*\(\s*\$_(GET|POST|REQUEST)',
    r'auth_pass\s*=\s*["\'][0-9a-f]{32}["\']',
    r'\$_SERVER\s*\[\s*["\']HTTP_[A-Z_]+["\']\s*\]\s*\)\s*\)\s*;?\s*\}?\s*eval',
]
SIGNATURE_RE = [re.compile(p, re.IGNORECASE) for p in SIGNATURES]

DANGEROUS_CALL_RE = re.compile(r'\b(eval|assert|create_function|call_user_func|call_user_func_array|preg_replace_callback|array_map|array_filter|usort|register_shutdown_function)\s*\(', re.I)
DECODER_RE = re.compile(r'\b(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode|rawurldecode)\s*\(', re.I)
LONG_LITERAL_RE = re.compile(r'["\']([A-Za-z0-9+/=]{200,})["\']')
ESCAPE_RE = re.compile(r'\\x[0-9a-fA-F]{2}|\\[0-7]{3}')
GLOBALS_TRICK_RE = re.compile(r'\$\{\s*["\']GLOBALS["\']\s*\}|\$GLOBALS\s*\[\s*\$GLOBALS', re.I)
SUPERGLOBAL_CALL_RE = re.compile(r'\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(', re.I)
CHR_RUN_RE = re.compile(r'(chr\s*\(\s*\d+\s*\)\s*\.\s*){20,}', re.I)
DYNAMIC_CALL_RE = re.compile(r'\$[a-zA-Z_]\w*\s*\(\s*\$[a-zA-Z_]\w*\s*\(\s*["\']', re.I)
HTACCESS_HANDLER_RE = re.compile(r'^\s*(AddHandler|SetHandler|AddType\b[^\n]*php|php_value|php_flag|php_admin_value)', re.I | re.M)
SILENCE_RE = re.compile(r'^\s*<\?php\s*(?://[^\n]*|#[^\n]*|/\*.*?\*/)?\s*(?:\?>)?\s*$', re.S)
COMMENT_BLOCK_RE = re.compile(r'/\*.*?\*/', re.S)
COMMENT_LINE_RE = re.compile(r'(?m)^[ \t]*(?://|#)[^\n]*$|(?<=[;{}\s])//[^\n]*$', re.M)


def rel(root, path):
    return os.path.relpath(path, root).replace(os.sep, '/')


def shannon(data):
    if not data:
        return 0.0
    counts = {}
    for ch in data:
        counts[ch] = counts.get(ch, 0) + 1
    total = float(len(data))
    return -sum((c / total) * math.log2(c / total) for c in counts.values())


def looks_random(basename):
    name = re.sub(r'\.(php|phtml|inc)$', '', basename.lower())
    if name in NAME_ALLOW or (name + '.php') in CORE_ROOT_FILES:
        return False
    if not re.fullmatch(r'[a-z0-9]{4,12}', name):
        return False
    letters = re.sub(r'[^a-z]', '', name)
    digits = len(name) - len(letters)
    vowels = len(re.findall(r'[aeiouy]', letters))
    ratio = (vowels / len(letters)) if letters else 0.0
    runs = re.findall(r'[^aeiouy]+', letters)
    longest = max((len(r) for r in runs), default=0)
    if digits >= 2:
        return True
    if digits == 1:
        return ratio < 0.45 or longest >= 3
    return ratio < 0.2 or longest >= 5 or (len(letters) >= 6 and vowels <= 1)


def is_silence_file(path, size):
    if size > 128:
        return False
    try:
        with open(path, 'rb') as fh:
            data = fh.read(256).decode('utf-8', 'replace')
    except OSError:
        return False
    return SILENCE_RE.match(data) is not None


def strip_comments(text):
    text = COMMENT_BLOCK_RE.sub(' ', text)
    return COMMENT_LINE_RE.sub('', text)


def content_verdicts(path, size):
    """Return the reasons a code file's contents earn: signature, obfuscated_code."""
    try:
        with open(path, 'rb') as fh:
            raw = fh.read()
    except OSError:
        return []
    text = raw.decode('utf-8', 'replace')
    if '<?' not in text and '<?php' not in text and not path.endswith(PHP_EXT):
        return []
    code = strip_comments(text)
    reasons = []
    if any(r.search(code) for r in SIGNATURE_RE):
        reasons.append('signature')

    score = 0
    has_call = DANGEROUS_CALL_RE.search(code) is not None
    has_decoder = DECODER_RE.search(code) is not None
    if has_call and has_decoder:
        score += 2
    literals = LONG_LITERAL_RE.findall(code)
    if literals:
        score += 1
        biggest = max(literals, key=len)
        if shannon(biggest) >= 5.4 and len(biggest) >= 400:
            score += 1
        if has_call:
            score += 1
    stripped = re.sub(r'\s+', '', code)
    if stripped:
        escapes = len(ESCAPE_RE.findall(code))
        if escapes >= 20 and (escapes * 4) / len(stripped) > 0.05:
            score += 2
    if GLOBALS_TRICK_RE.search(code):
        score += 2
    if SUPERGLOBAL_CALL_RE.search(code):
        score += 2
    if CHR_RUN_RE.search(code):
        score += 2
    if DYNAMIC_CALL_RE.search(code) and has_decoder:
        score += 1
    if score >= 3:
        reasons.append('obfuscated_code')
    return reasons


def md5_of(path):
    h = hashlib.md5()
    try:
        with open(path, 'rb') as fh:
            for chunk in iter(lambda: fh.read(1 << 16), b''):
                h.update(chunk)
    except OSError:
        return None
    return h.hexdigest()


class Scanner:
    def __init__(self, args):
        self.root = os.path.abspath(args.root)
        self.wordpress = args.wordpress
        self.allow_mu = set(a for a in (args.allow_mu or '').split(',') if a)
        self.max_bytes = max(64, args.max_file_kb) * 1024
        self.max_hits = max(20, args.max_hits)
        self.deadline = time.time() + max(30, args.time_budget)
        self.hits = {}
        self.per_rule = {}
        self.truncated = False
        self.files_seen = 0
        self.manifest = None
        self.core = {'version': None, 'verified': False, 'modified': 0, 'extra': 0, 'missing': 0}
        if args.manifest and os.path.isfile(args.manifest):
            try:
                with open(args.manifest, 'r', encoding='utf-8') as fh:
                    data = json.load(fh)
                self.manifest = data.get('checksums') or {}
                self.core['version'] = data.get('version')
            except (OSError, ValueError):
                self.manifest = None

    def emit(self, reason, path):
        if self.per_rule.get(reason, 0) >= self.max_hits:
            self.truncated = True
            return
        try:
            st = os.lstat(path)
        except OSError:
            return
        r = rel(self.root, path)
        entry = self.hits.setdefault(r, {'reasons': [], 'size': int(st.st_size), 'mtime': int(st.st_mtime), 'path': r})
        if reason not in entry['reasons']:
            entry['reasons'].append(reason)
            self.per_rule[reason] = self.per_rule.get(reason, 0) + 1

    def out_of_time(self):
        if time.time() > self.deadline:
            self.truncated = True
            return True
        return False

    def run(self):
        os.chdir(self.root)
        if self.wordpress:
            self.scan_root_names()
            self.scan_wp_content_names()
            self.scan_core()
        self.walk()
        return self

    # ---- WordPress name rules -------------------------------------------------
    def scan_root_names(self):
        for name in sorted(os.listdir('.')):
            full = os.path.join(self.root, name)
            if not os.path.isfile(full) or os.path.islink(full):
                continue
            lower = name.lower()
            if lower in KNOWN_FAMILY_FILES:
                self.emit('known_webshell_family', full)
            if lower.endswith(PHP_EXT) and lower not in CORE_ROOT_FILES:
                self.emit('unexpected_root_php', full)
            if lower.startswith('wp-') and lower.endswith('.php') and lower not in CORE_ROOT_FILES:
                self.emit('core_lookalike', full)

    def scan_wp_content_names(self):
        wc = os.path.join(self.root, 'wp-content')
        if os.path.isdir(wc):
            for name in sorted(os.listdir(wc)):
                full = os.path.join(wc, name)
                if os.path.isfile(full) and name.lower().endswith(PHP_EXT) and name.lower() not in KNOWN_DROPINS:
                    self.emit('random_name' if looks_random(name) else 'unexpected_content_php', full)
        mu = os.path.join(wc, 'mu-plugins')
        if os.path.isdir(mu):
            for name in sorted(os.listdir(mu)):
                full = os.path.join(mu, name)
                if os.path.isfile(full) and name.lower().endswith(PHP_EXT) and name not in self.allow_mu:
                    self.emit('unexpected_mu_plugin', full)

    # ---- Core verification ---------------------------------------------------
    def scan_core(self):
        version_file = os.path.join(self.root, 'wp-includes', 'version.php')
        if self.manifest is not None:
            self.verify_core_against_manifest()
            return
        # No manifest: files changed after the version file was written (an update rewrites both).
        if not os.path.isfile(version_file):
            return
        ref = os.stat(version_file).st_mtime + 3600
        for sub in ('wp-admin', 'wp-includes'):
            base = os.path.join(self.root, sub)
            for dirpath, dirs, files in os.walk(base):
                dirs[:] = [d for d in dirs if not os.path.islink(os.path.join(dirpath, d))]
                for f in files:
                    full = os.path.join(dirpath, f)
                    if f.lower().endswith('.php') and not os.path.islink(full):
                        try:
                            if os.stat(full).st_mtime > ref:
                                self.emit('core_modified_after_install', full)
                        except OSError:
                            pass
                    if f.lower().endswith(PHP_EXT) and looks_random(f):
                        self.emit('random_name', full)

    def verify_core_against_manifest(self):
        on_disk = set()
        for sub in ('wp-admin', 'wp-includes'):
            base = os.path.join(self.root, sub)
            if not os.path.isdir(base):
                continue
            for dirpath, dirs, files in os.walk(base):
                dirs[:] = [d for d in dirs if not os.path.islink(os.path.join(dirpath, d))]
                for f in files:
                    full = os.path.join(dirpath, f)
                    if os.path.islink(full):
                        continue
                    r = rel(self.root, full)
                    on_disk.add(r)
                    expected = self.manifest.get(r)
                    if expected is None:
                        self.emit('core_unexpected_file', full)
                        self.core['extra'] += 1
                    elif md5_of(full) != expected:
                        self.emit('core_checksum_mismatch', full)
                        self.core['modified'] += 1
        for name in CORE_ROOT_FILES:
            if name in ('wp-config.php', 'wp-config-sample.php'):
                continue
            full = os.path.join(self.root, name)
            expected = self.manifest.get(name)
            if expected is None:
                continue
            if os.path.isfile(full):
                on_disk.add(name)
                if md5_of(full) != expected:
                    self.emit('core_checksum_mismatch', full)
                    self.core['modified'] += 1
        for path in self.manifest:
            if path.startswith('wp-content/') or path in ('readme.html', 'license.txt', 'wp-config-sample.php'):
                continue
            if path in on_disk:
                continue
            if not (path.startswith('wp-admin/') or path.startswith('wp-includes/') or path in CORE_ROOT_FILES):
                continue
            self.core['missing'] += 1
            self.emit_missing(path)
        self.core['verified'] = True

    def emit_missing(self, r):
        if self.per_rule.get('core_missing_file', 0) >= self.max_hits:
            self.truncated = True
            return
        entry = self.hits.setdefault(r, {'reasons': [], 'size': 0, 'mtime': 0, 'path': r})
        if 'core_missing_file' not in entry['reasons']:
            entry['reasons'].append('core_missing_file')
            self.per_rule['core_missing_file'] = self.per_rule.get('core_missing_file', 0) + 1

    # ---- Tree walk: location, exposure, content --------------------------------
    def walk(self):
        for dirpath, dirs, files in os.walk(self.root):
            if self.out_of_time():
                return
            dirs[:] = [d for d in dirs if not os.path.islink(os.path.join(dirpath, d))]
            rdir = rel(self.root, dirpath)
            if rdir == '.':
                rdir = ''
            for d in list(dirs):
                if d in KNOWN_FAMILY_DIRS:
                    self.emit('known_webshell_family', os.path.join(dirpath, d))
            in_no_exec = any(rdir == n or rdir.startswith(n + '/') for n in NO_EXEC_DIRS)
            skip_content = any(rdir == s or rdir.startswith(s + '/') for s in SKIP_CONTENT_DIRS)
            for f in files:
                full = os.path.join(dirpath, f)
                if os.path.islink(full):
                    continue
                try:
                    st = os.lstat(full)
                except OSError:
                    continue
                if not stat.S_ISREG(st.st_mode):
                    continue
                self.files_seen += 1
                lower = f.lower()
                rpath = (rdir + '/' + f) if rdir else f
                if lower in KNOWN_FAMILY_FILES:
                    self.emit('known_webshell_family', full)
                if in_no_exec:
                    if lower.endswith(PHP_EXT) and not (lower == 'index.php' and is_silence_file(full, st.st_size)):
                        self.emit('php_in_uploads', full)
                    if lower == '.htaccess' and st.st_size <= 65536:
                        try:
                            with open(full, 'r', encoding='utf-8', errors='replace') as fh:
                                if HTACCESS_HANDLER_RE.search(fh.read()):
                                    self.emit('htaccess_php_handler', full)
                        except OSError:
                            pass
                if lower.endswith(IMAGE_LIKE_EXT) and st.st_size <= 262144:
                    try:
                        with open(full, 'rb') as fh:
                            head = fh.read(1024)
                        if b'<?php' in head:
                            self.emit('php_in_image', full)
                    except OSError:
                        pass
                if self.is_exposed(rdir, lower):
                    self.emit('exposed_backup', full)
                if skip_content or st.st_size > self.max_bytes:
                    continue
                if lower.endswith(PHP_EXT) or lower.endswith(('.ico', '.txt')) and st.st_size < 65536:
                    if lower == 'index.php' and is_silence_file(full, st.st_size):
                        continue
                    for reason in content_verdicts(full, st.st_size):
                        self.emit(reason, full)
            if self.out_of_time():
                return

    def is_exposed(self, rdir, lower):
        if rdir.startswith('wp-content/uploads') or rdir.startswith('wp-content/talksasa-disabled'):
            return False
        if rdir.startswith('vendor') or rdir.startswith('node_modules'):
            return False
        if lower in EXPOSED_EXACT:
            return True
        if lower.startswith('wp-config') and lower not in ('wp-config.php', 'wp-config-sample.php') and lower.endswith(('.php', '.bak', '.old', '.orig', '.txt', '.save', '~', '.swp')):
            return True
        if rdir == '' or rdir == 'wp-content' or rdir.startswith('wp-content/backup') or rdir.startswith('wp-content/ai1wm') or rdir.startswith('wp-content/updraft'):
            return lower.endswith(EXPOSED_SUFFIXES)
        return lower.endswith(('.sql', '.sql.gz'))

    def summary(self):
        return {
            'scan_version': SCAN_VERSION,
            'files_seen': self.files_seen,
            'hits': len(self.hits),
            'per_rule': self.per_rule,
            'truncated': self.truncated,
            'core': self.core,
        }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--root', default=None)
    ap.add_argument('--manifest', default=None)
    ap.add_argument('--wordpress', action='store_true')
    ap.add_argument('--allow-mu', default='talksasa-admin-sso.php')
    ap.add_argument('--max-file-kb', type=int, default=2048)
    ap.add_argument('--max-hits', type=int, default=200)
    ap.add_argument('--time-budget', type=int, default=240)
    ap.add_argument('--version', action='store_true')
    args = ap.parse_args()
    if args.version:
        print(SCAN_VERSION)
        return 0
    if not args.root or not os.path.isdir(args.root):
        print('__SUMMARY__\t' + json.dumps({'scan_version': SCAN_VERSION, 'error': 'root missing', 'hits': 0, 'truncated': False}))
        return 0
    scanner = Scanner(args).run()
    for entry in sorted(scanner.hits.values(), key=lambda e: e['path']):
        for reason in entry['reasons']:
            sys.stdout.write('%s\t%d\t%d\t%s\n' % (reason, entry['size'], entry['mtime'], entry['path']))
    sys.stdout.write('__SUMMARY__\t' + json.dumps(scanner.summary(), sort_keys=True) + '\n')
    return 0


if __name__ == '__main__':
    sys.exit(main())
