<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;

class ContainerOllamaModelService
{
    /**
     * Size keys shown to customers → official Ollama library tags.
     * Never treat these as Docker image tags.
     *
     * @var array<string, string>
     */
    public const MODEL_TAGS = [
        '7b' => 'mistral:7b',
        '8b' => 'ministral-3:8b',
    ];

    public function __construct(
        private ContainerStackCommandService $stackCommands,
    ) {}

    public static function normalizeSize(?string $selectedVersion): string
    {
        $size = strtolower(trim((string) $selectedVersion));

        return array_key_exists($size, self::MODEL_TAGS) ? $size : '7b';
    }

    public static function modelTag(?string $selectedVersion): string
    {
        return self::MODEL_TAGS[self::normalizeSize($selectedVersion)];
    }

    /**
     * @return array{skipped: bool, model: string, message: string}
     */
    public function pullIfNeeded(
        Service $service,
        ContainerDeployment $deployment,
        SSHService $ssh,
        string $containerPath,
        string $composeService,
    ): array {
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'ollama') {
            return [
                'skipped' => true,
                'model' => '',
                'message' => 'Not an Ollama stack.',
            ];
        }

        $selectedVersion = $deployment->selected_version
            ?? (is_array($service->service_meta) ? ($service->service_meta['selected_version'] ?? null) : null);
        $model = self::modelTag(is_string($selectedVersion) ? $selectedVersion : null);

        $this->stackCommands->execInContainer(
            $ssh,
            $containerPath,
            $composeService,
            'ollama pull '.$model,
            '/',
            1200,
        );

        return [
            'skipped' => false,
            'model' => $model,
            'message' => 'Pulled '.$model,
        ];
    }
}
