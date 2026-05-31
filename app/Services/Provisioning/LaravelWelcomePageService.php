<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;

class LaravelWelcomePageService
{
    /** @var list<string> */
    private const DEFAULT_WELCOME_MARKERS = [
        'cloud.laravel.com',
        'Laravel Cloud',
        'Deploy now',
    ];

    public function templatePath(): string
    {
        return (string) config(
            'containers.laravel_init.welcome_template',
            resource_path('container-templates/laravel/welcome.blade.php')
        );
    }

    public function welcomeRelativePath(): string
    {
        return 'resources/views/welcome.blade.php';
    }

    public function usesDefaultLaravelWelcome(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        try {
            $content = $this->readWelcomeContent($ssh, $deployment);
        } catch (\Throwable) {
            return false;
        }

        return $this->contentIsDefaultLaravelWelcome($content);
    }

    public function contentIsDefaultLaravelWelcome(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        foreach (self::DEFAULT_WELCOME_MARKERS as $marker) {
            if (str_contains($content, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function apply(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $templatePath = $this->templatePath();
        if (! is_readable($templatePath)) {
            throw new \RuntimeException('Talksasa Laravel welcome template is missing.');
        }

        $hostAppPath = app(ContainerAppDirectoryService::class)->hostAppPath($deployment);
        $targetPath = $hostAppPath.'/'.$this->welcomeRelativePath();

        $ssh->mkdirp(dirname($targetPath));
        $ssh->upload((string) file_get_contents($templatePath), $targetPath);
    }

    public function applyIfDefault(SSHService $ssh, ContainerDeployment $deployment): bool
    {
        if (! $this->usesDefaultLaravelWelcome($ssh, $deployment)) {
            return false;
        }

        $this->apply($ssh, $deployment);

        return true;
    }

    private function readWelcomeContent(SSHService $ssh, ContainerDeployment $deployment): string
    {
        $hostAppPath = app(ContainerAppDirectoryService::class)->hostAppPath($deployment);
        $welcomePath = $hostAppPath.'/'.$this->welcomeRelativePath();
        $pathArg = escapeshellarg($welcomePath);

        return trim($ssh->exec("[ -f {$pathArg} ] && cat {$pathArg} || true", 20));
    }
}
