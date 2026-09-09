<?php

namespace Tests\Feature;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\ContainerConsoleTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console renders its tab strip in one partial and its Alpine navigation
 * guard in another. setTab() ignores any tab missing from that guard, so a
 * mismatch shows a full strip of buttons that do nothing when clicked, with no
 * server-side error. These tests compare what we render against what we allow.
 */
class ContainerConsoleTabsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_tab_button_on_the_admin_page_is_one_alpine_will_switch_to(): void
    {
        [$admin, , $service] = $this->containerService();

        $html = $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertTabStripMatchesGuard($html, 'admin service page');
    }

    #[Test]
    public function every_tab_button_on_the_customer_page_is_one_alpine_will_switch_to(): void
    {
        [, $customer, $service] = $this->containerService();

        $html = $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertTabStripMatchesGuard($html, 'customer container page');
    }

    #[Test]
    public function the_console_offers_the_full_set_of_sections_once_an_app_is_deployed(): void
    {
        [$admin, , $service] = $this->containerService();

        $html = $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->getContent();

        foreach (ContainerConsoleTabs::BASE as $tab) {
            $this->assertContains($tab, $this->allowedTabs($html), "The {$tab} tab is not reachable.");
        }
    }

    #[Test]
    public function a_deep_link_opens_its_tab_and_an_unknown_one_falls_back_to_overview(): void
    {
        [$admin, , $service] = $this->containerService();

        $html = $this->actingAs($admin)
            ->get(route('admin.services.show', $service).'?tab=logs')
            ->assertOk()
            ->getContent();

        $this->assertSame('logs', $this->initialTab($html));

        $html = $this->actingAs($admin)
            ->get(route('admin.services.show', $service).'?tab=not-a-tab')
            ->assertOk()
            ->getContent();

        $this->assertSame('overview', $this->initialTab($html));
    }

    #[Test]
    public function rendering_the_admin_console_does_not_leave_customer_pages_pointing_at_admin_routes(): void
    {
        [$admin, $customer, $service] = $this->containerService();

        $adminHtml = $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/admin/services/'.$service->id.'/container/',
            $adminHtml,
            'The admin console must drive the admin container routes.'
        );

        $html = $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/services/'.$service->id.'/container/', $html);
        $this->assertStringNotContainsString('/admin/services/'.$service->id.'/container/', $html);
    }

    private function assertTabStripMatchesGuard(string $html, string $context): void
    {
        $allowed = $this->allowedTabs($html);
        $rendered = $this->renderedTabs($html);

        $this->assertNotEmpty($rendered, "Expected a tab strip on the {$context}.");

        $dead = array_values(array_diff($rendered, $allowed));
        $this->assertSame([], $dead, sprintf(
            'These tabs render on the %s but Alpine refuses to switch to them: %s',
            $context,
            implode(', ', $dead),
        ));
    }

    /**
     * Tabs the Alpine component will switch to.
     *
     * @return list<string>
     */
    private function allowedTabs(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/const allowedTabs = JSON\.parse\(\'(.*)\'\);/',
            $html,
            'The console script no longer declares its tab allow-list.'
        );

        preg_match('/const allowedTabs = JSON\.parse\(\'(.*)\'\);/', $html, $matches);

        // @js() emits a JSON document inside an escaped JavaScript string literal.
        $json = json_decode('"'.$matches[1].'"');
        $tabs = json_decode((string) $json, true);

        $this->assertIsArray($tabs, 'The tab allow-list did not decode to a list.');

        return $tabs;
    }

    /**
     * Tabs that have a clickable button in the markup.
     *
     * @return list<string>
     */
    private function renderedTabs(string $html): array
    {
        preg_match_all('/setTab\(\'([a-z0-9-]+)\'\)/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function initialTab(string $html): string
    {
        preg_match('/x-data="containerTabs\((?:&#039;|\')([a-z0-9-]+)(?:&#039;|\')\)"/', $html, $matches);

        $this->assertNotEmpty($matches, 'The console did not declare an initial tab.');

        return $matches[1];
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
