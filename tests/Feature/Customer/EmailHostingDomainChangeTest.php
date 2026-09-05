<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailHostingDomainChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_open_email_page_with_change_domain_form(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        [$customer, $service] = $this->emailService();

        $this->actingAs($customer)
            ->get(route('customer.services.email.show', $service))
            ->assertOk()
            ->assertSee('Change mail domain')
            ->assertSee('Type the domain to confirm')
            ->assertSee('shop.example.com');
    }

    public function test_customer_can_change_empty_mail_domain(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/get/mailbox/all/') || str_contains($url, '/get/alias/all/')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/add/domain') || str_contains($url, '/delete/domain') || str_contains($url, '/edit/')) {
                return Http::response([['type' => 'success', 'msg' => 'ok']], 200);
            }

            return Http::response([], 200);
        });

        [$customer, $service] = $this->emailService();

        $this->actingAs($customer)
            ->put(route('customer.services.email.domain.update', $service), [
                'domain' => 'shop.example.com',
                'domain_confirmation' => 'shop.example.com',
            ])
            ->assertRedirect(route('customer.services.email.show', ['service' => $service, 'tab' => 'manage']))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame('shop.example.com', $service->service_meta['mailcow_domain']);
        $this->assertSame('shop.example.com', $service->external_reference);
    }

    public function test_domain_confirmation_must_match(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        [$customer, $service] = $this->emailService();

        $this->actingAs($customer)
            ->from(route('customer.services.email.show', $service))
            ->put(route('customer.services.email.domain.update', $service), [
                'domain' => 'shop.example.com',
                'domain_confirmation' => 'other.example.com',
            ])
            ->assertRedirect(route('customer.services.email.show', $service))
            ->assertSessionHasErrors('domain_confirmation');

        $service->refresh();
        $this->assertSame('old.com', $service->service_meta['mailcow_domain']);
    }

    public function test_change_is_rejected_when_mailboxes_exist(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/mailbox/all/old.com' => Http::response([
                ['username' => 'info@old.com'],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        [$customer, $service] = $this->emailService();

        $this->actingAs($customer)
            ->from(route('customer.services.email.show', $service))
            ->put(route('customer.services.email.domain.update', $service), [
                'domain' => 'shop.example.com',
                'domain_confirmation' => 'shop.example.com',
            ])
            ->assertRedirect(route('customer.services.email.show', $service))
            ->assertSessionHasErrors('error');

        $service->refresh();
        $this->assertSame('old.com', $service->service_meta['mailcow_domain']);
    }

    public function test_other_customer_cannot_change_mail_domain(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        [, $service] = $this->emailService();
        $stranger = User::factory()->customer()->create();

        $this->actingAs($stranger)
            ->put(route('customer.services.email.domain.update', $service), [
                'domain' => 'shop.example.com',
                'domain_confirmation' => 'shop.example.com',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function emailService(): array
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        Domain::create([
            'user_id' => $customer->id,
            'name' => 'shop',
            'extension' => '.example.com',
            'status' => 'active',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com'],
        ]);

        return [$customer, $service];
    }
}
