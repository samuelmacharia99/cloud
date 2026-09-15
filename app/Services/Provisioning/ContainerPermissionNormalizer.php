<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * Puts a deployed application's files back to the modes the platform ships:
 * owner www-data, folders 755, files 644, secrets 640, upload and cache
 * folders group-writable. Modes and ownership only; nothing is removed.
 */
class ContainerPermissionNormalizer
{
    public function __construct(
        private readonly WordPressContainerHardeningService $wordpress,
        private readonly ContainerAppDirectoryService $appDirectory,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function normalize(SSHService $ssh, Service $service, ContainerDeployment $deployment, string $stack): array
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $hostAppPath = $containerPath.'/app';

        if ($stack === 'wordpress') {
            $this->wordpress->ensureWritableFilesystem($ssh, $hostAppPath, $containerPath, $deployment->container_name);
            $ssh->exec($this->stripDangerousBitsCommand($hostAppPath, true), 120);

            return [
                'success' => true,
                'message' => 'WordPress files are back to 755/644 with wp-config.php at 640, owned by www-data, uploads and plugins writable. World-writable and setuid bits are gone.',
            ];
        }

        $this->appDirectory->normalizePermissions($ssh, $deployment);
        if ($stack === 'laravel') {
            $this->appDirectory->ensureLaravelWritableLayoutOnHost($ssh, $hostAppPath);
        }
        $ssh->exec($this->stripDangerousBitsCommand($hostAppPath, false), 120);

        return [
            'success' => true,
            'message' => 'Files are owned by the web user with folders 775 and files 664 inside the app, secrets at 640, and no world-writable, setuid or executable upload files.',
        ];
    }

    /**
     * The part no template-specific normaliser does: drop the bits that let a
     * compromise spread, and close secrets files. Dependency trees are skipped.
     */
    public function stripDangerousBitsCommand(string $hostAppPath, bool $wordpress): string
    {
        $root = escapeshellarg(rtrim($hostAppPath, '/'));
        $prune = "\\( -path {$root}/vendor -o -path {$root}/node_modules -o -path {$root}/.git \\) -prune -o";
        $uploads = $wordpress
            ? "{$root}/wp-content/uploads {$root}/wp-content/cache {$root}/wp-content/upgrade {$root}/wp-content/languages"
            : "{$root}/storage/app/public {$root}/public/uploads";

        return 'if [ -d '.$root.' ]; then'
            .' find '.$root.' '.$prune.' -perm -o+w -exec chmod o-w {} + 2>/dev/null;'
            .' find '.$root.' '.$prune.' -type f \\( -perm -4000 -o -perm -2000 \\) -exec chmod u-s,g-s {} + 2>/dev/null;'
            .' for d in '.$uploads.'; do [ -d "$d" ] && find "$d" -type f -perm -o+x -exec chmod a-x {} + 2>/dev/null; done;'
            .' for f in '.$root.'/wp-config.php '.$root.'/.env '.$root.'/.env.production '.$root.'/.env.local '.$root.'/auth.json; do'
            .'   [ -f "$f" ] && chown 33:33 "$f" && chmod 640 "$f"; done;'
            .($wordpress ? ' [ -d '.$root.'/wp-content ] && chown -R 33:33 '.$root.'/wp-content 2>/dev/null;' : '')
            .' true; fi';
    }
}
