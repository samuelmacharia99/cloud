<?php

namespace Tests\Feature;

use App\Jobs\ProvisionResellerSslJob;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_saving_custom_domain_does_not_queue_background_ssl_job(): void
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

        Queue::assertNotPushed(ProvisionResellerSslJob::class);

        $reseller->refresh();
        $this->assertSame('external', $reseller->settings['branding']['ssl']['status'] ?? null);
    }

    public function test_provision_ssl_redirects_with_cli_instructions(): void
    {
        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'custom_domain' => 'billing.acme.test',
                ],
            ],
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.settings.branding.ssl.provision'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_reseller_can_upload_and_delete_branding_logo(): void
    {
        Storage::fake('public');

        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                ],
            ],
        ]);

        $file = UploadedFile::fake()->image('logo.png', 500, 150);

        $this->actingAs($reseller)
            ->post(route('reseller.settings.branding.upload'), [
                'type' => 'logo',
                'file' => $file,
            ])
            ->assertRedirect(route('reseller.settings.index', ['tab' => 'branding']))
            ->assertSessionHas('success');

        $reseller->refresh();
        $logoPath = $reseller->settings['branding']['logo_path'] ?? null;
        $logoUrl = $reseller->settings['branding']['logo_url'] ?? null;

        $this->assertNotNull($logoPath);
        $this->assertNotNull($logoUrl);
        Storage::disk('public')->assertExists($logoPath);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.settings.index', ['tab' => 'branding']));

        $response->assertOk();
        $response->assertSee('Your Logo', false);
        $response->assertDontSee('Platform default', false);

        $this->actingAs($reseller)
            ->delete(route('reseller.settings.branding.delete'), [
                'type' => 'logo',
            ])
            ->assertRedirect(route('reseller.settings.index', ['tab' => 'branding']))
            ->assertSessionHas('success');

        $reseller->refresh();
        $this->assertArrayNotHasKey('logo_url', $reseller->settings['branding'] ?? []);
        $this->assertArrayNotHasKey('logo_path', $reseller->settings['branding'] ?? []);
        Storage::disk('public')->assertMissing($logoPath);

        $this->actingAs($reseller)
            ->get(route('reseller.settings.index', ['tab' => 'branding']))
            ->assertOk()
            ->assertDontSee('Your Logo', false);
    }

    public function test_reseller_can_replace_existing_logo(): void
    {
        Storage::fake('public');

        $reseller = User::factory()->create([
            'is_reseller' => true,
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                ],
            ],
        ]);

        $firstFile = UploadedFile::fake()->image('logo.png', 500, 150);

        $this->actingAs($reseller)
            ->post(route('reseller.settings.branding.upload'), [
                'type' => 'logo',
                'file' => $firstFile,
            ])
            ->assertRedirect();

        $reseller->refresh();
        $firstPath = $reseller->settings['branding']['logo_path'];

        $secondFile = UploadedFile::fake()->image('new-logo.jpg', 500, 150);

        $this->actingAs($reseller)
            ->post(route('reseller.settings.branding.upload'), [
                'type' => 'logo',
                'file' => $secondFile,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reseller->refresh();
        $secondPath = $reseller->settings['branding']['logo_path'];

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }
}
