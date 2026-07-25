<?php
/**
 * Talksasa → Mailcow passwordless SOGo SSO.
 *
 * Deploy to: /opt/mailcow-dockerized/data/web/talksasa-sogo-sso.php
 * Requires: ALLOW_ADMIN_EMAIL_LOGIN=y and inc/talksasa-sso.secret.php
 *
 * Query: mailbox, exp (unix ts), sig = HMAC-SHA256(mailbox|exp, secret)
 * Secret must match the Mailcow API token stored on the Talksasa node.
 */

$ALLOW_ADMIN_EMAIL_LOGIN = (bool) preg_match(
    '/^([yY][eE][sS]|[yY])+$/',
    (string) ($_ENV['ALLOW_ADMIN_EMAIL_LOGIN'] ?? getenv('ALLOW_ADMIN_EMAIL_LOGIN') ?: '')
);

if (! $ALLOW_ADMIN_EMAIL_LOGIN) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ALLOW_ADMIN_EMAIL_LOGIN must be y in mailcow.conf (then recreate containers).';
    exit;
}

$mailbox = strtolower(trim((string) ($_GET['mailbox'] ?? '')));
$exp = (int) ($_GET['exp'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');

if ($mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid mailbox.';
    exit;
}

$now = time();
if ($exp < $now || $exp > ($now + 600)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'SSO link expired. Open the mailbox again from Talksasa.';
    exit;
}

$secretFile = __DIR__.'/inc/talksasa-sso.secret.php';
if (! is_readable($secretFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'SSO secret missing. Run setup-mailcow-node.sh enable-sso on this host.';
    exit;
}

$secret = require $secretFile;
if (! is_string($secret) || $secret === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'SSO secret empty.';
    exit;
}

$expected = hash_hmac('sha256', $mailbox.'|'.$exp, $secret);
if (! hash_equals($expected, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid SSO signature.';
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/inc/prerequisites.inc.php';

$exists = false;
try {
    $stmt = $pdo->prepare('SELECT `username` FROM `mailbox` WHERE `username` = :u AND `active` = "1" LIMIT 1');
    $stmt->execute([':u' => $mailbox]);
    $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Mailbox lookup failed.';
    exit;
}

if (! $exists) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Mailbox not found.';
    exit;
}

$session_var_user_allowed = 'sogo-sso-user-allowed';
if (! isset($_SESSION[$session_var_user_allowed]) || ! is_array($_SESSION[$session_var_user_allowed])) {
    $_SESSION[$session_var_user_allowed] = [];
}
if (! in_array($mailbox, $_SESSION[$session_var_user_allowed], true)) {
    $_SESSION[$session_var_user_allowed][] = $mailbox;
}

$_SESSION['mailcow_cc_username'] = $mailbox;
$_SESSION['mailcow_cc_role'] = 'user';
unset($_SESSION['pending_pw_update'], $_SESSION['pending_tfa_setup'], $_SESSION['dual-login']);

try {
    $stmt = $pdo->prepare('REPLACE INTO `sasl_log` (`service`, `app_password`, `username`, `real_rip`) VALUES ("SSO", 0, :username, :remote_addr)');
    $stmt->execute([
        ':username' => $mailbox,
        ':remote_addr' => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
} catch (Throwable $e) {
    // non-fatal
}

header('Location: /SOGo/so/'.rawurlencode($mailbox).'/');
exit;
