<?php

namespace Tests\Feature;

use App\Jobs\ProvisionResellerSslJob;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ResellerBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_defaults_for_unknown_customer(): void
    {
        $resolver = app(ResellerBrandingResolver::class);
        $defaults = $resolver->defaults();

        $this->assertFalse($defaults['is_white_label']);
        $this->assertNull($defaults['reseller_id']);
    }

    public function test_resolver_returns_reseller_branding_for_managed_customer(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                    'tagline' => 'Fast & reliable',
                    'primary_color' => '#ff0000',
                ],
            ],
        ]);

        $customer = User::factory()->create([
            'reseller_id' => $reseller->id,
        ]);

        $branding = app(ResellerBrandingResolver::class)->forCustomer($customer);

        $this->assertSame('Acme Hosting', $branding['company_name']);
        $this->assertSame('Fast & reliable', $branding['tagline']);
        $this->assertSame('#ff0000', $branding['primary_color']);
        $this->assertTrue($branding['is_white_label']);
    }

    public function test_resolver_finds_reseller_by_custom_domain(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Billing Co',
                    'custom_domain' => 'billing.example.test',
                ],
            ],
        ]);

        $found = app(ResellerBrandingResolver::class)->resolveFromHost('billing.example.test');

        $this->assertNotNull($found);
        $this->assertSame($reseller->id, $found->id);
    }

    public function test_signed_registration_url_includes_reseller_param(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);

        URL::forceRootUrl('https://app.example.test');

        $url = app(ResellerBrandingResolver::class)->signedRegistrationUrl($reseller);

        $this->assertStringContainsString('reseller='.$reseller->id, $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_registration_assigns_reseller_from_signed_link(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);

        URL::forceRootUrl('http://localhost');

        $url = URL::temporarySignedRoute('register', now()->addHour(), [
            'reseller' => $reseller->id,
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSessionHas('registration_reseller_id', $reseller->id);
    }

    public function test_branding_status_tracks_readiness(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => ['company_name' => 'Ready Co'],
                'smtp' => [
                    'enabled' => true,
                    'host' => 'smtp.example.test',
                    'from_address' => 'noreply@ready.test',
                ],
            ],
        ]);

        $status = app(ResellerBrandingResolver::class)->status($reseller);

        $this->assertTrue($status['portal']['ready']);
        $this->assertTrue($status['email']['ready']);
        $this->assertFalse($status['payments']['ready']);
    }

    public function test_saving_custom_domain_queues_background_ssl_job(): void
    {
        Queue::fake();

        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                ],
            ],
        ]);

        app(ResellerSettingsService::class)->updateBrandingSettings($reseller, [
            'company_name' => 'Acme Hosting',
            'custom_domain' => 'billing.acme.test',
        ]);

        Queue::assertPushed(ProvisionResellerSslJob::class, function (ProvisionResellerSslJob $job) use ($reseller) {
            return $job->resellerId === $reseller->id;
        });

        $reseller->refresh();
        $this->assertSame('pending', $reseller->settings['branding']['ssl']['status'] ?? null);
    }
}
