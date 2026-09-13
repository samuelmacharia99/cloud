<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDeployOptions;
use App\Services\Provisioning\ContainerDeployResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deployed Laravel, PHP or WordPress stack shows which version it runs and
 * lets the customer switch it; the switch is a redeploy on the new version
 * and nothing else.
 */
class ContainerPhpVersionTabTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_console_shows_the_tab_with_the_current_php_version(): void
    {
        [$customer, $service] = $this->deployed('laravel', '8.1-cli');

        $this->actingAs($customer)->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->assertSee('PHP Version')
            ->assertSee('data-php-version-current>PHP 8.1', false)
            ->assertSee('data-php-version-option="8.4"', false)
            ->assertSee(route('customer.services.container.php-version.update', $service), false);
    }

    #[Test]
    public function switching_records_the_version_and_redeploys_once(): void
    {
        [$customer, $service] = $this->deployed('laravel', '8.3');

        $deployments = $this->mock(ContainerDeploymentService::class);
        $deployments->shouldReceive('deploy')
            ->once()
            ->withArgs(fn (Service $s, ContainerDeployOptions $o) => $s->is($service)
                && $s->service_meta['selected_version'] === '8.2'
                && $o->isRedeploy && ! $o->resetDatabase)
            ->andReturn(new ContainerDeployResult);

        $this->actingAs($customer)
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '8.2'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Application redeployed on PHP 8.2.');

        $this->assertSame('8.2', $service->fresh()->service_meta['selected_version']);
    }

    #[Test]
    public function the_same_version_an_unknown_version_and_a_running_deploy_do_not_redeploy(): void
    {
        [$customer, $service] = $this->deployed('laravel', '8.3');
        $this->mock(ContainerDeploymentService::class)->shouldNotReceive('deploy');

        $this->actingAs($customer)
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '8.3'])
            ->assertSessionHas('info', 'The application already runs PHP 8.3.');

        $this->actingAs($customer)
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '7.4'])
            ->assertSessionHasErrors(['error' => 'That version is not offered for this stack.']);

        $service->containerDeployment->update(['status' => 'deploying']);
        $this->actingAs($customer)
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '8.1'])
            ->assertSessionHasErrors('error');
        $this->assertSame('8.3', $service->fresh()->service_meta['selected_version']);
    }

    #[Test]
    public function a_failed_redeploy_keeps_the_previous_version(): void
    {
        [$customer, $service] = $this->deployed('laravel', '8.3');
        $this->mock(ContainerDeploymentService::class)->shouldReceive('deploy')->once()->andThrow(new \RuntimeException('build exploded'));

        $this->actingAs($customer)
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '8.4'])
            ->assertSessionHasErrors('error');

        $this->assertSame('8.3', $service->fresh()->service_meta['selected_version']);
        $this->assertSame('8.3', $service->fresh()->containerDeployment->selected_version);
    }

    #[Test]
    public function wordpress_offers_its_image_tags_and_other_stacks_have_no_tab(): void
    {
        [$customer, $wordpress] = $this->deployed('wordpress', null, ['latest', '6.6-php8.3-apache', '6.4-php8.1-apache']);

        $this->actingAs($customer)->get(route('customer.services.container.show', $wordpress))
            ->assertOk()
            ->assertSee('PHP Version')
            ->assertSee('WordPress 6.4 · PHP 8.1')
            ->assertSee('data-php-version-current>Latest WordPress (current PHP)', false);

        [, $node] = $this->deployed('nodejs', null);
        $this->actingAs($node->user)->get(route('customer.services.container.show', $node))
            ->assertOk()
            ->assertDontSee('data-php-version-form', false);
    }

    #[Test]
    public function another_customer_cannot_switch_and_the_admin_route_works(): void
    {
        [$customer, $service] = $this->deployed('laravel', '8.3');
        $this->mock(ContainerDeploymentService::class)->shouldReceive('deploy')->once()->andReturn(new ContainerDeployResult);

        $this->actingAs(User::factory()->customer()->create())
            ->post(route('customer.services.container.php-version.update', $service), ['version' => '8.2'])
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.services.container.php-version.update', $service), ['version' => '8.2'])
            ->assertRedirect();
    }

    /**
     * @param  list<string>|null  $versions
     * @return array{0: User, 1: Service}
     */
    private function deployed(string $slug, ?string $selectedVersion, ?array $versions = null): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->where('slug', $slug)->first()
            ?? ContainerTemplate::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true, 'hosting_type' => 'container']);
        if ($versions !== null) {
            $template->forceFill(['versions' => $versions])->save();
        }
        $product = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $node = Node::factory()->containerHost()->create(['ssh_username' => null, 'ssh_password' => null]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
            'service_meta' => array_filter(['selected_version' => $selectedVersion, 'language_slug' => $slug]),
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-'.$slug,
            'status' => 'running',
            'selected_version' => $selectedVersion,
        ]);

        return [$customer, $service->fresh(['containerDeployment', 'product.containerTemplate', 'user'])];
    }
}
