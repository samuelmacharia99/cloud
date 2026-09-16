<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The WordPress administrator account behind a container site: what the
 * platform knows about it, and resetting its password or email from the
 * console. The admin is found the same way the one-time login does, and
 * every change is made through WordPress's own functions inside the
 * container, so it works on a converted site as well as a fresh install.
 */
class WordPressAdminAccountService
{
    public const MIN_PASSWORD_LENGTH = 12;

    public const MAX_PASSWORD_LENGTH = 128;

    public function __construct(private WordPressAdminLoginService $login) {}

    /**
     * What the console shows before any change: the admin the platform
     * created or last reset. A converted site may know nothing yet.
     *
     * @return array<string, mixed>|null
     */
    public function panelState(Service $service, ?ContainerDeployment $deployment): ?array
    {
        if (! $service->isWordPressContainer()) {
            return null;
        }

        $credentials = $this->storedCredentials($service);
        $env = is_array($deployment?->env_values) ? $deployment->env_values : [];
        $meta = is_array($service->service_meta['wordpress_admin'] ?? null) ? $service->service_meta['wordpress_admin'] : [];

        $username = (string) ($meta['login'] ?? $credentials['admin_username'] ?? $env['WORDPRESS_ADMIN_USER'] ?? '');
        $email = (string) ($meta['email'] ?? $credentials['admin_email'] ?? $env['WORDPRESS_ADMIN_EMAIL'] ?? '');

        return [
            'admin_username' => $username !== '' ? $username : null,
            'admin_email' => $email !== '' ? $email : null,
            'known' => $username !== '',
            'installed_by_platform' => filled($service->service_meta['wordpress_installed_at'] ?? null),
            'password_reset_at' => $meta['password_reset_at'] ?? null,
            'email_changed_at' => $meta['email_changed_at'] ?? null,
            'container_running' => (bool) $deployment?->isRunning(),
            'min_password_length' => self::MIN_PASSWORD_LENGTH,
        ];
    }

    /**
     * Set a new password on the site's administrator, generated unless the
     * caller supplies one, and sign that user out everywhere. The stored
     * credentials and environment values follow, so what the platform
     * records is what the site accepts.
     *
     * @return array{user_id: int, username: string, email: string, password: string, generated: bool}
     */
    public function resetPassword(Service $service, User $actor, ?string $password = null, ?SSHService $ssh = null): array
    {
        $generated = $password === null || $password === '';
        $password = $generated ? $this->generatePassword() : $password;
        $this->assertPasswordAcceptable($password);

        $result = $this->runAdminChange($service, ['TALKASA_WP_NEW_PASSWORD' => $password], $ssh);

        $this->recordAdmin($service, $result, ['password_reset_at' => now()->toIso8601String(), 'password_reset_by_user_id' => $actor->id], $password);

        AdminActivityService::log(
            'wordpress.admin_password_reset',
            sprintf('Reset the WordPress admin password for %s (user %s) on service #%d', $service->name, $result['login'], $service->id),
            $service,
            ['actor_user_id' => $actor->id, 'wp_user_id' => $result['id'], 'generated' => $generated]
        );

        return [
            'user_id' => $result['id'],
            'username' => $result['login'],
            'email' => $result['email'],
            'password' => $password,
            'generated' => $generated,
        ];
    }

    /**
     * Change the administrator's email so password recovery mail and notices
     * reach the person who runs the site today.
     *
     * @return array{user_id: int, username: string, email: string}
     */
    public function updateEmail(Service $service, User $actor, string $email, ?SSHService $ssh = null): array
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address for the WordPress administrator.');
        }

        $result = $this->runAdminChange($service, ['TALKASA_WP_NEW_EMAIL' => $email], $ssh);

        $this->recordAdmin($service, $result, ['email_changed_at' => now()->toIso8601String(), 'email_changed_by_user_id' => $actor->id]);

        AdminActivityService::log(
            'wordpress.admin_email_changed',
            sprintf('Changed the WordPress admin email for %s (user %s) to %s on service #%d', $service->name, $result['login'], $result['email'], $service->id),
            $service,
            ['actor_user_id' => $actor->id, 'wp_user_id' => $result['id']]
        );

        return ['user_id' => $result['id'], 'username' => $result['login'], 'email' => $result['email']];
    }

    public function generatePassword(): string
    {
        // Letters and digits only: nothing a customer has to escape when they paste it.
        return Str::password(20, symbols: false);
    }

    public function assertPasswordAcceptable(string $password): void
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH || $length > self::MAX_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'The WordPress admin password must be between %d and %d characters.',
                self::MIN_PASSWORD_LENGTH,
                self::MAX_PASSWORD_LENGTH
            ));
        }
        if (preg_match('/[\r\n\0]/', $password)) {
            throw new \InvalidArgumentException('The password cannot contain line breaks.');
        }
    }

    /**
     * PHP run inside the container through wp-load: apply what the
     * environment carries (a new password, a new email, or both) to the
     * administrator with the given id, and report the account back.
     */
    public function adminChangeScript(int $userId): string
    {
        $userId = max(1, $userId);

        return <<<PHP
@ini_set('display_errors', '0');
error_reporting(0);
require '/var/www/html/wp-load.php';
\$id = {$userId};
\$user = get_userdata(\$id);
if (! \$user) {
    echo 'TALKASA_WP_ADMIN_ERROR=User ' . \$id . ' no longer exists';
    exit(0);
}
\$password = (string) getenv('TALKASA_WP_NEW_PASSWORD');
\$email = (string) getenv('TALKASA_WP_NEW_EMAIL');
if (\$email !== '') {
    \$existing = get_user_by('email', \$email);
    if (\$existing && (int) \$existing->ID !== \$id) {
        echo 'TALKASA_WP_ADMIN_ERROR=Another WordPress user already has that email';
        exit(0);
    }
    \$updated = wp_update_user(['ID' => \$id, 'user_email' => \$email]);
    if (is_wp_error(\$updated)) {
        echo 'TALKASA_WP_ADMIN_ERROR=' . \$updated->get_error_message();
        exit(0);
    }
}
if (\$password !== '') {
    wp_set_password(\$password, \$id);
    if (class_exists('WP_Session_Tokens')) {
        WP_Session_Tokens::get_instance(\$id)->destroy_all();
    }
}
\$user = get_userdata(\$id);
echo 'TALKASA_WP_ADMIN_RESULT=' . json_encode(['id' => (int) \$user->ID, 'login' => (string) \$user->user_login, 'email' => (string) \$user->user_email]);
exit(0);
PHP;
    }

    /**
     * @return array{id: int, login: string, email: string}
     */
    public function parseAdminResult(string $output): array
    {
        if (preg_match('/TALKASA_WP_ADMIN_ERROR=([^\r\n]+)/', $output, $m) === 1) {
            throw new RuntimeException('WordPress refused the change: '.trim($m[1]));
        }
        if (preg_match('/TALKASA_WP_ADMIN_RESULT=(\{[^\r\n]*\})/', $output, $m) !== 1) {
            throw new RuntimeException('WordPress did not confirm the change. The site may still be starting; try again in a moment.');
        }
        $decoded = json_decode($m[1], true);
        if (! is_array($decoded) || (int) ($decoded['id'] ?? 0) <= 0) {
            throw new RuntimeException('WordPress returned an unreadable answer to the change.');
        }

        return [
            'id' => (int) $decoded['id'],
            'login' => (string) ($decoded['login'] ?? ''),
            'email' => (string) ($decoded['email'] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $changes  environment handed to the in-container script
     * @return array{id: int, login: string, email: string}
     */
    private function runAdminChange(Service $service, array $changes, ?SSHService $ssh): array
    {
        $service->loadMissing(['product.containerTemplate', 'containerDeployment.node']);
        if (! $service->isWordPressContainer()) {
            throw new RuntimeException('This service is not a WordPress Application Hosting container.');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->isRunning()) {
            throw new RuntimeException('Start the site before changing its WordPress admin account.');
        }
        if (! $deployment->node) {
            throw new RuntimeException('Container host is not configured.');
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $appService = $deployment->container_name;
        $pathArg = escapeshellarg($containerPath);
        $appArg = escapeshellarg($appService);
        $env = is_array($deployment->env_values) ? $deployment->env_values : [];

        $owned = $ssh === null;
        $ssh ??= SSHService::forNode($deployment->node);

        try {
            $probe = $this->login->administratorProbeScript(
                (string) ($env['WORDPRESS_ADMIN_USER'] ?? 'admin'),
                (string) ($env['WORDPRESS_ADMIN_EMAIL'] ?? '')
            );
            $output = $ssh->exec(
                "cd {$pathArg} && docker compose exec -T {$appArg} php -d display_errors=0 -r ".escapeshellarg($probe),
                60
            );
            $userId = $this->login->parseAdministratorIdFromOutput($output);
            if ($userId <= 0) {
                throw new RuntimeException($this->login->noAdministratorMessage($this->login->parseDiagnosticsFromOutput($output)));
            }

            $envFlags = '';
            foreach ($changes as $key => $value) {
                $envFlags .= ' -e '.escapeshellarg($key.'='.$value);
            }
            $result = $ssh->exec(
                "cd {$pathArg} && docker compose exec -T{$envFlags} {$appArg} php -d display_errors=0 -r ".escapeshellarg($this->adminChangeScript($userId)),
                60
            );

            return $this->parseAdminResult($result);
        } finally {
            if ($owned) {
                $ssh->disconnect();
            }
        }
    }

    /**
     * @param  array{id: int, login: string, email: string}  $result
     * @param  array<string, mixed>  $stamps
     */
    private function recordAdmin(Service $service, array $result, array $stamps, ?string $password = null): void
    {
        $service->refresh();

        $credentials = $this->storedCredentials($service);
        $credentials['admin_username'] = $result['login'];
        $credentials['admin_email'] = $result['email'];
        if ($password !== null) {
            $credentials['admin_password'] = $password;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $existing = is_array($meta['wordpress_admin'] ?? null) ? $meta['wordpress_admin'] : [];
        $meta['wordpress_admin'] = array_merge($existing, [
            'user_id' => $result['id'],
            'login' => $result['login'],
            'email' => $result['email'],
        ], $stamps);

        $service->update([
            'credentials' => json_encode($credentials),
            'service_meta' => $meta,
        ]);

        $deployment = $service->containerDeployment;
        if ($deployment) {
            $env = is_array($deployment->env_values) ? $deployment->env_values : [];
            $env['WORDPRESS_ADMIN_USER'] = $result['login'];
            $env['WORDPRESS_ADMIN_EMAIL'] = $result['email'];
            if ($password !== null) {
                $env['WORDPRESS_ADMIN_PASSWORD'] = $password;
            }
            $deployment->update(['env_values' => $env]);
        }

        Log::info('WordPress admin account updated from the console', [
            'service_id' => $service->id,
            'wp_user_id' => $result['id'],
            'changes' => array_keys($stamps),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCredentials(Service $service): array
    {
        $raw = $service->credentials;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
