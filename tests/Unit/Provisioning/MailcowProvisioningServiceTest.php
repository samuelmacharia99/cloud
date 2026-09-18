<?php

namespace Tests\Unit\Provisioning;

use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailcowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailcowProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_limits_from_product_resource_limits(): void
    {
        $product = new Product([
            'resource_limits' => [
                'mailboxes' => 5,
                'aliases' => 15,
                'quota_mb' => 10240,
                'mailbox_quota_mb' => 2048,
                'msgs_per_day' => 250,
            ],
        ]);

        $limits = (new MailcowProvisioningService)->limitsForProduct($product);

        $this->assertSame(5, $limits['mailboxes']);
        $this->assertSame(15, $limits['aliases']);
        $this->assertSame(10240, $limits['quota_mb']);
        $this->assertSame(2048, $limits['mailbox_quota_mb']);
        $this->assertSame(250, $limits['msgs_per_day']);
    }

    #[Test]
    public function it_defaults_msgs_per_day_when_missing(): void
    {
        $product = new Product([
            'resource_limits' => [
                'mailboxes' => 5,
                'aliases' => 15,
                'quota_mb' => 10240,
                'mailbox_quota_mb' => 2048,
            ],
        ]);

        $limits = (new MailcowProvisioningService)->limitsForProduct($product);

        $this->assertSame((int) config('mailcow.default_msgs_per_day', 500), $limits['msgs_per_day']);
    }

    #[Test]
    public function it_posts_domain_daily_ratelimit_to_mailcow(): void
    {
        Http::fake([
            'mail.example.com/api/v1/edit/rl-domain' => Http::response([
                ['type' => 'success', 'msg' => 'rl_saved'],
            ], 200),
        ]);

        $node = Node::factory()->mailcow()->create([
            'api_url' => 'https://mail.example.com',
            'api_token' => 'test-key',
        ]);

        $result = MailcowService::forNode($node)->editDomainRatelimit('example.com', 500, 'd');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/api/v1/edit/rl-domain')
                && ($data['items'][0] ?? null) === 'example.com'
                && ($data['attr']['rl_value'] ?? null) === '500'
                && ($data['attr']['rl_frame'] ?? null) === 'd';
        });
    }

    #[Test]
    public function it_changes_an_empty_mail_domain_and_removes_the_old_mailcow_domain(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/get/mailbox/all/old.com') || str_contains($url, '/get/alias/all/old.com')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/get/domain/shop.example.com')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/add/domain') || str_contains($url, '/edit/rl-domain') || str_contains($url, '/delete/domain')) {
                return Http::response([['type' => 'success', 'msg' => 'ok']], 200);
            }

            return Http::response([], 200);
        });

        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        $owned = Domain::create([
            'user_id' => $customer->id,
            'name' => 'shop',
            'extension' => '.example.com',
            'status' => 'active',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com'],
        ]);

        $changed = (new MailcowProvisioningService)->changeMailDomain($service, 'shop.example.com');

        $this->assertSame('shop.example.com', $changed);
        $service->refresh();
        $this->assertSame('shop.example.com', $service->external_reference);
        $this->assertSame('shop.example.com', $service->service_meta['mailcow_domain']);
        $this->assertSame('old.com', $service->service_meta['previous_mailcow_domain']);
        $this->assertSame($owned->id, $service->service_meta['domain_id']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/add/domain'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/delete/domain'));
    }

    #[Test]
    public function it_rejects_changing_a_mail_domain_that_still_has_mailboxes(): void
    {
        Http::fake([
            'mail.example.com/api/v1/get/mailbox/all/old.com' => Http::response([
                ['username' => 'info@old.com'],
            ], 200),
            'mail.example.com/api/v1/get/alias/all/old.com' => Http::response([], 200),
        ]);

        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delete all mailboxes');

        (new MailcowProvisioningService)->changeMailDomain($service, 'shop.example.com');
    }

    #[Test]
    public function it_rejects_a_mail_domain_used_by_another_email_service(): void
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'taken.com',
            'service_meta' => ['mailcow_domain' => 'taken.com'],
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another email service already uses taken.com.');

        (new MailcowProvisioningService)->changeMailDomain($service, 'taken.com');
    }

    #[Test]
    public function an_operator_replaces_a_populated_mail_domain_and_the_new_one_gets_its_info_inbox(): void
    {
        $deleted = [];
        $added = [];
        Http::fake(function ($request) use (&$deleted, &$added) {
            $url = $request->url();
            if (str_contains($url, '/get/mailbox/all/old.com')) {
                return Http::response([
                    ['username' => 'head@old.com'],
                    ['username' => 'bursar@old.com'],
                ], 200);
            }
            if (str_contains($url, '/get/alias/all/old.com')) {
                return Http::response([['id' => 3, 'address' => 'info@old.com']], 200);
            }
            if (str_contains($url, '/get/mailbox/all/new.example.com')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/delete/domain')) {
                $deleted[] = $request->data();

                return Http::response([['type' => 'success', 'msg' => 'ok']], 200);
            }
            if (str_contains($url, '/add/mailbox')) {
                $added[] = $request->data();

                return Http::response([['type' => 'success', 'msg' => 'ok']], 200);
            }
            if (str_contains($url, '/add/domain') || str_contains($url, '/edit/rl-domain')) {
                return Http::response([['type' => 'success', 'msg' => 'ok']], 200);
            }

            return Http::response([], 200);
        });

        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->mailcow()->create();
        $product = Product::factory()->emailHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'mailcow',
            'external_reference' => 'old.com',
            'service_meta' => ['mailcow_domain' => 'old.com', 'operator_inbox' => 'info@old.com'],
        ]);

        $mailcow = app(MailcowProvisioningService::class);
        $contents = $mailcow->currentMailDomainContents($service);
        $this->assertSame(['old.com', 2, 1], [$contents['domain'], $contents['mailboxes'], $contents['aliases']]);

        $result = $mailcow->replaceMailDomain($service->fresh(['product', 'node', 'user']), 'new.example.com', $admin);

        $this->assertSame('new.example.com', $result['domain']);
        $this->assertSame('old.com', $result['previous_domain']);
        $this->assertSame(2, $result['deleted_mailboxes']);
        $this->assertSame(1, $result['deleted_aliases']);
        $this->assertSame('info@new.example.com', $result['info_mailbox']);
        $this->assertNotEmpty($result['info_password'], 'the operator has to be able to hand the password over');

        $service->refresh();
        $this->assertSame('new.example.com', $service->external_reference);
        $this->assertSame('new.example.com', $service->service_meta['mailcow_domain']);
        $this->assertSame('old.com', $service->service_meta['previous_mailcow_domain']);
        $this->assertSame('info@new.example.com', $service->service_meta['operator_inbox']);
        $replacement = $service->service_meta['mailcow_domain_replacements'][0];
        $this->assertSame(['old.com', 'new.example.com', 2, 1, $admin->id], [
            $replacement['from'], $replacement['to'], $replacement['destroyed_mailboxes'], $replacement['destroyed_aliases'], $replacement['by_user_id'],
        ]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'service.mail_domain_replaced']);

        // delete/domain takes a bare list of domains.
        $this->assertSame([['old.com']], array_map(fn (array $row): array => array_values($row), $deleted));
        $this->assertSame('info', $added[0]['local_part'] ?? null);
        $this->assertSame('new.example.com', $added[0]['domain'] ?? null);
    }

    #[Test]
    public function the_customer_facing_change_still_refuses_to_destroy_mail(): void
    {
        Http::fake(fn () => Http::response([['username' => 'head@old.com']], 200));

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delete all mailboxes on old.com');
        app(MailcowProvisioningService::class)->changeMailDomain($service, 'new.example.com');
    }
}
