<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\MailcowProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMailDomainReplacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_page_offers_the_change_and_names_what_would_be_destroyed(): void
    {
        [$admin, $service] = $this->emailService();
        $this->mock(MailcowProvisioningService::class, function ($mock) {
            $mock->shouldReceive('currentMailDomainContents')->andReturn([
                'domain' => 'old.com', 'mailboxes' => 4, 'aliases' => 2, 'readable' => true, 'message' => null,
            ]);
        });

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Mail domain')
            ->assertSee('4 mailbox(es) and 2 alias(es)')
            ->assertSee('Type old.com to confirm');
    }

    public function test_an_admin_replaces_the_domain_and_is_shown_the_new_inbox_password_once(): void
    {
        [$admin, $service] = $this->emailService();
        $this->mock(MailcowProvisioningService::class, function ($mock) use ($service) {
            $mock->shouldReceive('currentMailDomainContents')->andReturn([
                'domain' => 'old.com', 'mailboxes' => 4, 'aliases' => 2, 'readable' => true, 'message' => null,
            ]);
            $mock->shouldReceive('replaceMailDomain')->once()
                ->withArgs(fn (Service $s, string $domain, User $actor) => $s->id === $service->id && $domain === 'new.example.com' && $actor->is_admin)
                ->andReturn([
                    'domain' => 'new.example.com',
                    'previous_domain' => 'old.com',
                    'deleted_mailboxes' => 4,
                    'deleted_aliases' => 2,
                    'info_mailbox' => 'info@new.example.com',
                    'info_password' => 'Sup3rSecret123456',
                    'warnings' => [],
                ]);
        });

        $this->actingAs($admin)
            ->post(route('admin.services.mail-domain.replace', $service), [
                'domain' => 'new.example.com',
                'confirm_current_domain' => 'OLD.com',
                'understood' => '1',
            ])
            ->assertRedirect(route('admin.services.show', $service))
            ->assertSessionHas('mail_info_password', 'Sup3rSecret123456');

        $this->assertStringContainsString('old.com was deleted with 4 mailbox(es)', session('success'));
        $this->assertStringContainsString('Created info@new.example.com', session('success'));
    }

    public function test_the_wrong_confirmation_or_a_missing_tick_changes_nothing(): void
    {
        [$admin, $service] = $this->emailService();
        $this->mock(MailcowProvisioningService::class, function ($mock) {
            $mock->shouldReceive('currentMailDomainContents')->andReturn([
                'domain' => 'old.com', 'mailboxes' => 4, 'aliases' => 2, 'readable' => true, 'message' => null,
            ]);
            $mock->shouldNotReceive('replaceMailDomain');
        });

        $this->actingAs($admin)
            ->from(route('admin.services.show', $service))
            ->post(route('admin.services.mail-domain.replace', $service), [
                'domain' => 'new.example.com',
                'confirm_current_domain' => 'something-else.com',
                'understood' => '1',
            ])
            ->assertSessionHasErrors('confirm_current_domain');

        $this->actingAs($admin)
            ->from(route('admin.services.show', $service))
            ->post(route('admin.services.mail-domain.replace', $service), [
                'domain' => 'new.example.com',
                'confirm_current_domain' => 'old.com',
            ])
            ->assertSessionHasErrors('understood');
    }

    public function test_a_customer_cannot_replace_a_mail_domain(): void
    {
        [, $service] = $this->emailService();
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->post(route('admin.services.mail-domain.replace', $service), [
                'domain' => 'new.example.com',
                'confirm_current_domain' => 'old.com',
                'understood' => '1',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function emailService(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => Product::factory()->emailHosting()->create()->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com'],
        ]);

        return [$admin, $service];
    }
}
