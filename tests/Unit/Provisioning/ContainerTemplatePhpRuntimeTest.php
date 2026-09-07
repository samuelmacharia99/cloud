<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerTemplatePhpRuntimeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ensure_php_runtime_creates_the_missing_catalog_row(): void
    {
        $this->assertNull(ContainerTemplate::query()->where('slug', 'php')->first());

        $template = ContainerTemplate::ensurePhpRuntime();

        $this->assertSame('php', $template->slug);
        $this->assertTrue($template->is_active);
        $this->assertSame(8080, (int) $template->default_port);
        $this->assertSame('/app', $template->volume_paths['app_data'] ?? null);
        $this->assertSame(1, ContainerTemplate::query()->where('slug', 'php')->count());
        $this->assertTrue($template->is($template->fresh()));
    }

    #[Test]
    public function ensure_php_runtime_reactivates_and_repairs_an_existing_row(): void
    {
        $existing = ContainerTemplate::factory()->create([
            'slug' => 'php',
            'is_active' => false,
            'default_port' => 0,
            'volume_paths' => [],
        ]);

        $template = ContainerTemplate::ensurePhpRuntime();

        $this->assertTrue($template->is($existing->fresh()));
        $this->assertTrue($template->is_active);
        $this->assertSame(8080, (int) $template->default_port);
        $this->assertSame('/app', $template->volume_paths['app_data'] ?? null);
    }
}
