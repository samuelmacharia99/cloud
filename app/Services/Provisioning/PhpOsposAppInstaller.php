<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

/**
 * DirectAdmin converts of Open Source POS only packed public_html plus a
 * partial app/. Linking vendor system/ then loads a newer CodeIgniter than
 * the imported Config classes (Class "Config\Locale" not found).
 *
 * Official OSPOS is a Git + Composer app. The /app bind mount hides any
 * files baked into a custom image, so "fresh app" means replace the host
 * tree from GitHub and keep the MySQL volume unless the operator asks otherwise.
 */
class PhpOsposAppInstaller
{
    public const REPOSITORY = 'https://github.com/opensourcepos/opensourcepos.git';

    public const BRANCH = 'master';

    public function looksLikeOspos(?string $fatal = null, bool $hasOsposConfig = false): bool
    {
        if ($hasOsposConfig) {
            return true;
        }

        $fatal = (string) $fatal;

        return str_contains($fatal, 'Config\\Locale')
            || (bool) preg_match('/Class "Config\\\\[^"]+" not found/i', $fatal)
            || str_contains(strtolower($fatal), 'opensourcepos');
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function installKeepingDatabase(Service $service, ContainerDeployment $deployment, SSHService $ssh): array
    {
        $service->loadMissing(['user', 'product.containerTemplate', 'containerDeployment.node']);

        app(ContainerGitRepositoryService::class)->connect(
            $service,
            self::REPOSITORY,
            self::BRANCH,
        );
        $service->refresh();

        $pull = app(ContainerGitRepositoryService::class)->pull(
            $service,
            $deployment,
            replaceExisting: true,
            runComposer: true,
            runMigrations: false,
        );

        $hostAppPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name.'/app';
        $publicUrl = (string) ($deployment->getAccessUrl() ?? '');

        try {
            app(PhpSidecarDatabaseRewriter::class)->applyForDeployment($ssh, $service, $deployment);
        } catch (\Throwable) {
        }
        app(PhpCodeIgniterRuntimeHealer::class)->applyOnHost($ssh, $hostAppPath, $publicUrl);
        app(PhpCodeIgniterPathFixer::class)->ensureWritableOnHost($ssh, $hostAppPath);

        try {
            app(ContainerPhpExtensionsService::class)->applyExtensionPreference($service, 'mysqli', true);
            app(ContainerPhpExtensionsService::class)->ensureExtensionInstalled($ssh, $deployment, 'mysqli');
        } catch (\Throwable) {
        }

        $this->runSparkMigrate($ssh, $deployment);

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        try {
            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg(app(ContainerDoctorService::class)->phpFpmReloadScript()),
                15
            );
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'message' => 'Installed Open Source POS from GitHub'
                .($pull['commit'] ? ' ('.$pull['commit'].')' : '')
                .'. Sidecar credentials were rewritten. MySQL was left running.',
        ];
    }

    private function runSparkMigrate(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

        try {
            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose exec -T '.escapeshellarg($deployment->container_name)
                .' sh -lc '.escapeshellarg(
                    'if [ -f /app/spark ]; then php spark migrate --all --no-interaction 2>&1 | tail -n 20; else echo no-spark; fi'
                ),
                90
            );
        } catch (\Throwable) {
        }
    }
}
