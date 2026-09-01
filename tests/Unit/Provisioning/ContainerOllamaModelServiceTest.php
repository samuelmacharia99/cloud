<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerOllamaModelService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerOllamaModelServiceTest extends TestCase
{
    #[Test]
    public function it_maps_mistral_family_sizes_to_official_library_tags(): void
    {
        $this->assertSame('7b', ContainerOllamaModelService::normalizeSize('7B'));
        $this->assertSame('8b', ContainerOllamaModelService::normalizeSize('8B'));
        $this->assertSame('7b', ContainerOllamaModelService::normalizeSize('latest'));
        $this->assertSame('mistral:7b', ContainerOllamaModelService::modelTag('7b'));
        $this->assertSame('ministral-3:8b', ContainerOllamaModelService::modelTag('8b'));
        $this->assertSame('mistral:7b', ContainerOllamaModelService::modelTag(null));
    }
}
