<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\LaravelProjectPathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LaravelProjectPathResolverTest extends TestCase
{
    #[Test]
    public function it_maps_container_and_host_project_roots(): void
    {
        $resolver = new LaravelProjectPathResolver;

        $this->assertSame('/app', $resolver->containerProjectRoot(''));
        $this->assertSame('/app/core', $resolver->containerProjectRoot('core'));
        $this->assertSame('/opt/talksasa/containers/demo/app', $resolver->hostProjectRoot('/opt/talksasa/containers/demo/app', ''));
        $this->assertSame(
            '/opt/talksasa/containers/demo/app/core',
            $resolver->hostProjectRoot('/opt/talksasa/containers/demo/app', 'core')
        );
    }

    #[Test]
    public function it_reads_document_root_from_service_meta(): void
    {
        $resolver = new LaravelProjectPathResolver;
        $service = new \App\Models\Service([
            'service_meta' => [
                'laravel_project_root' => 'core',
                'laravel_document_root' => '/app',
            ],
        ]);

        $this->assertSame('/app/core', $resolver->projectRootFromServiceMeta($service));
        $this->assertSame('/app', $resolver->documentRootFromServiceMeta($service));
    }
}
