<?php

namespace Tests\Feature;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The application console ships thousands of lines of inline JavaScript across
 * shared partials. One stray character anywhere in a block makes the browser
 * discard the whole script, which leaves every tab dead and Alpine unbound —
 * with nothing failing server side to warn us. These tests parse what we render.
 */
class ContainerConsoleScriptSyntaxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_container_console_emits_parsable_javascript(): void
    {
        [$admin, , $service] = $this->containerService();

        $html = $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertInlineScriptsParse($html, 'admin service page');
    }

    #[Test]
    public function customer_container_console_emits_parsable_javascript(): void
    {
        [, $customer, $service] = $this->containerService();

        $html = $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertInlineScriptsParse($html, 'customer container page');
    }

    private function assertInlineScriptsParse(string $html, string $context): void
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('Node is not available to parse the rendered JavaScript.');
        }

        $scripts = $this->inlineScripts($html);
        $this->assertNotEmpty($scripts, "Expected inline console scripts on the {$context}.");

        foreach ($scripts as $index => $script) {
            $path = tempnam(sys_get_temp_dir(), 'console-script-').'.js';
            file_put_contents($path, $script);

            try {
                $process = new Process([$node, '--check', $path]);
                $process->run();

                $this->assertTrue($process->isSuccessful(), sprintf(
                    "Inline script #%d on the %s does not parse:\n%s",
                    $index + 1,
                    $context,
                    trim($process->getErrorOutput()),
                ));
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function inlineScripts(string $html): array
    {
        preg_match_all('/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $html, $matches, PREG_SET_ORDER);

        $scripts = [];
        foreach ($matches as $match) {
            $attributes = $match[1];

            // Externals have nothing inline to parse, and template/JSON blocks are not JavaScript.
            if (preg_match('/\bsrc\s*=/i', $attributes)) {
                continue;
            }
            if (preg_match('/type\s*=\s*["\'](?!text\/javascript|module)/i', $attributes)) {
                continue;
            }
            if (trim($match[2]) === '') {
                continue;
            }

            $scripts[] = $match[2];
        }

        return $scripts;
    }

    private function nodeBinary(): ?string
    {
        foreach (['node', '/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
            $process = new Process([$candidate, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{0: User, 1: User, 2: Service}
     */
    private function containerService(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'name' => 'Node.js',
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
            'service_meta' => ['language_slug' => 'nodejs'],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-nodejs',
            'assigned_port' => 30022,
        ]);

        return [$admin, $customer, $service->fresh()];
    }
}
