<?php

namespace Tests\Feature\Reseller;

use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\ResellerHostedAccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HostedDirectAdminAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_users' => 10,
            'price' => 500,
            'active' => true,
            'disk_pool_gb' => 50,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_bulk_link_flashes_exact_failure_messages(): void
    {
        $reseller = $this->reseller();

        $this->mock(ResellerHostedAccountLinkService::class, function ($mock): void {
            $mock->shouldReceive('bulkLink')
                ->once()
                ->andReturn([
                    'linked' => 0,
                    'failed' => [
                        [
                            'username' => 'orphanuser',
                            'error' => 'Email is required to create a customer.',
                        ],
                    ],
                ]);
        });

        $response = $this->actingAs($reseller)
            ->post(route('reseller.directadmin-accounts.bulk-link'), [
                'da_usernames' => ['orphanuser'],
                'link' => 'unlinked',
            ]);

        $response->assertRedirect(route('reseller.customers.index', ['link' => 'unlinked']));
        $response->assertSessionHas('error', 'No accounts were linked.');
        $response->assertSessionHas('link_failures', [
            [
                'username' => 'orphanuser',
                'error' => 'Email is required to create a customer.',
            ],
        ]);
    }

    public function test_single_link_flashes_validation_error_message(): void
    {
        $reseller = $this->reseller();

        $this->mock(ResellerHostedAccountLinkService::class, function ($mock): void {
            $mock->shouldReceive('linkAccount')
                ->once()
                ->andThrow(ValidationException::withMessages([
                    'email' => ['Email is required to create a customer.'],
                ]));
        });

        $response = $this->actingAs($reseller)
            ->post(route('reseller.directadmin-accounts.link'), [
                'da_username' => 'orphanuser',
                'link' => 'unlinked',
            ]);

        $response->assertRedirect(route('reseller.customers.index', ['link' => 'unlinked']));
        $response->assertSessionHas('error', 'Email is required to create a customer.');
        $response->assertSessionHas('open_da_link', 'orphanuser');
    }
}
