<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ProjectRecipeService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectAdminToContainerConvertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_classify_mailboxes_treats_username_as_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
            ['account' => 'info', 'email' => 'info@example.com'],
            ['account' => 'sales@example.com', 'email' => 'sales@example.com'],
        ]);

        $this->assertTrue($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame('acmeuser@example.com', $result['default_mailboxes'][0]['email']);
        $this->assertCount(2, $result['extra_mailboxes']);
    }

    public function test_unowned_directadmin_domain_errors_are_not_fatal(): void
    {
        $mail = app(DirectAdminToMailcowMigrationService::class);

        $this->assertTrue($mail->isUnownedDirectAdminDomain(
            'DirectAdmin API HTTP 500: {"error":"Could not execute your request","result":"You do not own that domain"}'
        ));
        $this->assertFalse($mail->isUnownedDirectAdminDomain('CMD_API_POP failed'));
    }

    public function test_classify_mailboxes_only_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
        ]);

        $this->assertFalse($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame([], $result['extra_mailboxes']);
    }

    public function test_can_revert_and_restore_previous_directadmin_product(): void
    {
        $daProduct = Product::query()->create([
            'name' => 'Silver DA',
            'slug' => 'silver-da-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1000,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $wpTemplate = ContainerTemplate::query()->create([
            'name' => 'WordPress',
            'slug' => 'wordpress-revert-'.uniqid(),
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);

        $containerProduct = Product::query()->create([
            'name' => 'WP App',
            'slug' => 'wp-app-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2000,
            'is_active' => true,
            'container_template_id' => $wpTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $containerProduct->id,
            'name' => 'converted-service',
            'status' => 'active',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'node_id' => null,
            'service_meta' => [
                'username' => 'sisallov',
                'domain' => 'sisallove.com',
                'da_convert' => [
                    'status' => 'failed',
                    'previous' => [
                        'product_id' => $daProduct->id,
                        'node_id' => null,
                        'provisioning_driver_key' => 'directadmin',
                        'custom_price' => null,
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $this->assertTrue($convert->canRevertToDirectAdmin($service));

        $reverted = $convert->revertToDirectAdmin($service);

        $this->assertTrue($reverted->isSharedHosting());
        $this->assertSame($daProduct->id, $reverted->product_id);
        $this->assertNull($reverted->node_id);
        $this->assertSame('directadmin', $reverted->provisioning_driver_key);
        $this->assertSame('reverted', $reverted->service_meta['da_convert']['status']);
        $this->assertFalse($convert->canRevertToDirectAdmin($reverted->fresh()));
    }

    public function test_can_force_revert_stuck_running_convert(): void
    {
        $daProduct = Product::query()->create([
            'name' => 'DA Silver',
            'slug' => 'da-silver-stuck',
            'type' => 'shared_hosting',
            'monthly_price' => 1000,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);
        $containerProduct = Product::query()->create([
            'name' => 'WP App',
            'slug' => 'wp-app-stuck',
            'type' => 'container_hosting',
            'monthly_price' => 2000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $containerProduct->id,
            'name' => 'stuck-convert',
            'status' => 'provisioning',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'da_convert' => [
                    'status' => 'running',
                    'started_at' => now()->subMinutes(40)->toIso8601String(),
                    'heartbeat_at' => now()->subMinutes(40)->toIso8601String(),
                    'previous' => [
                        'product_id' => $daProduct->id,
                        'node_id' => null,
                        'provisioning_driver_key' => 'directadmin',
                        'custom_price' => null,
                        'status' => 'active',
                    ],
                ],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $this->assertTrue($convert->convertLooksStuck($service->service_meta['da_convert']));
        $this->assertTrue($convert->canRevertToDirectAdmin($service));
    }

    public function test_available_wordpress_products_lists_templated_products(): void
    {
        $template = ContainerTemplate::query()->create([
            'name' => 'WordPress Application',
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'WP Starter',
            'slug' => 'wp-starter',
            'type' => 'container_hosting',
            'monthly_price' => 1500,
            'is_active' => true,
            'container_template_id' => $template->id,
            'provisioning_driver_key' => 'container',
        ]);

        Product::query()->create([
            'name' => 'Laravel Pro',
            'slug' => 'laravel-pro',
            'type' => 'container_hosting',
            'monthly_price' => 2000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $service = app(DirectAdminToContainerConvertService::class);
        $pick = $service->availableWordPressProducts();

        $this->assertFalse($pick['fallback']);
        $this->assertTrue($pick['products']->contains('id', $product->id));
        $this->assertTrue($service->productIsWordPressContainer($product->fresh('containerTemplate')));
    }

    public function test_available_products_for_laravel_and_static_stacks(): void
    {
        $laravelTemplate = ContainerTemplate::query()->create([
            'name' => 'Laravel Application',
            'slug' => 'laravel-'.uniqid(),
            'docker_image' => 'talksasa/laravel-runtime:8.3',
            'is_active' => true,
        ]);
        $staticTemplate = ContainerTemplate::query()->create([
            'name' => 'Static Website',
            'slug' => 'static-site-'.uniqid(),
            'docker_image' => 'nginx:alpine',
            'is_active' => true,
        ]);

        $laravelProduct = Product::query()->create([
            'name' => 'Laravel Application Hosting',
            'slug' => 'laravel-app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2500,
            'is_active' => true,
            'container_template_id' => $laravelTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);
        $staticProduct = Product::query()->create([
            'name' => 'Static Application Hosting',
            'slug' => 'static-app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 800,
            'is_active' => true,
            'container_template_id' => $staticTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);

        $laravelPick = $convert->availableProductsForStack('laravel');
        $this->assertFalse($laravelPick['fallback']);
        $this->assertTrue($laravelPick['products']->contains('id', $laravelProduct->id));
        $this->assertTrue($convert->productMatchesStack($laravelProduct->fresh('containerTemplate'), 'laravel'));

        $staticPick = $convert->availableProductsForStack('static_or_php');
        $this->assertTrue($staticPick['products']->contains('id', $staticProduct->id));
        $this->assertTrue($convert->productMatchesStack($staticProduct->fresh('containerTemplate'), 'static_or_php'));
    }

    public function test_application_hosting_catalog_lists_every_plan_and_recommends_stack_match(): void
    {
        $wpTemplate = ContainerTemplate::query()->create([
            'name' => 'WordPress',
            'slug' => 'wordpress-catalog-'.uniqid(),
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);
        $laravelTemplate = ContainerTemplate::query()->create([
            'name' => 'Laravel',
            'slug' => 'laravel-catalog-'.uniqid(),
            'docker_image' => 'talksasa/laravel-runtime:8.3',
            'is_active' => true,
        ]);

        $wpProduct = Product::query()->create([
            'name' => 'WP Starter',
            'slug' => 'wp-catalog-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 1500,
            'is_active' => true,
            'container_template_id' => $wpTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);
        $laravelProduct = Product::query()->create([
            'name' => 'Laravel Pro',
            'slug' => 'laravel-catalog-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 3000,
            'is_active' => true,
            'container_template_id' => $laravelTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $catalog = app(DirectAdminToContainerConvertService::class)->applicationHostingCatalog('laravel');

        $this->assertTrue($catalog['products']->contains('id', $wpProduct->id));
        $this->assertTrue($catalog['products']->contains('id', $laravelProduct->id));
        $this->assertTrue($catalog['recommended']->contains('id', $laravelProduct->id));
        $this->assertFalse($catalog['recommended']->contains('id', $wpProduct->id));
        $this->assertFalse($catalog['fallback']);
    }

    public function test_classifies_laravel_when_artisan_is_above_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/acme/domains/shop.example/public_html';

        $classified = $migrator->classifyDetectedMarkers(
            implode("\n", [
                'DIR:'.$docroot,
                'IDX:'.$docroot,
                'ART:/home/acme/domains/shop.example',
                'CMP:/home/acme/domains/shop.example',
            ]),
            $docroot
        );

        $this->assertSame('laravel', $classified['stack']);
        $this->assertSame('/home/acme/domains/shop.example', $classified['app_root']);
        $this->assertFalse($classified['has_wp_config']);
    }

    public function test_classifies_wordpress_from_wp_config_in_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/acme/domains/blog.example/public_html';

        $classified = $migrator->classifyDetectedMarkers(
            "WP:{$docroot}\nDIR:{$docroot}",
            $docroot
        );

        $this->assertSame('wordpress', $classified['stack']);
        $this->assertTrue($classified['has_wp_config']);
        $this->assertSame($docroot, $classified['app_root']);
    }

    public function test_classifies_nodejs_from_package_json_in_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/sigtunaco/domains/sigtuna.org/public_html';

        $classified = $migrator->classifyDetectedMarkers(
            implode("\n", [
                'DIR:'.$docroot,
                'IDX:'.$docroot,
                'PKG:'.$docroot,
            ]),
            $docroot
        );

        $this->assertSame('nodejs', $classified['stack']);
        $this->assertSame($docroot, $classified['app_root']);
        $this->assertFalse($classified['has_wp_config']);
    }

    public function test_classifies_nodejs_when_package_json_is_above_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/sigtunaco/domains/sigtuna.org/public_html';
        $parent = '/home/sigtunaco/domains/sigtuna.org';

        $classified = $migrator->classifyDetectedMarkers(
            implode("\n", [
                'DIR:'.$docroot,
                'IDX:'.$docroot,
                'PKG:'.$parent,
                'NJS:'.$parent,
            ]),
            $docroot
        );

        $this->assertSame('nodejs', $classified['stack']);
        $this->assertSame($parent, $classified['app_root']);
    }

    public function test_php_composer_wins_over_package_json(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/acme/domains/api.example/public_html';
        $parent = '/home/acme/domains/api.example';

        $classified = $migrator->classifyDetectedMarkers(
            implode("\n", [
                'DIR:'.$docroot,
                'CMP:'.$parent,
                'PKG:'.$parent,
            ]),
            $docroot
        );

        $this->assertSame('php', $classified['stack']);
        $this->assertSame($parent, $classified['app_root']);
    }

    public function test_skips_junk_files_listed_as_domains(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $this->assertFalse($migrator->isConvertibleDomainLabel('download.svg'));
        $this->assertFalse($migrator->isConvertibleDomainLabel('backup.sql'));
        $this->assertFalse($migrator->isConvertibleDomainLabel('lost+found'));
        $this->assertFalse($migrator->isConvertibleDomainLabel('default'));
        $this->assertTrue($migrator->isConvertibleDomainLabel('sigtuna.org'));
        $this->assertTrue($migrator->isConvertibleDomainLabel('selfie.ke'));
        $this->assertTrue($migrator->isConvertibleDomainLabel('app.theharbor.co.ke'));
        $this->assertTrue($migrator->isConvertibleDomainLabel('thnkdigtal.zip'));
    }

    public function test_application_hosting_catalog_recommends_nodejs_plans(): void
    {
        $nodeTemplate = ContainerTemplate::query()->create([
            'name' => 'Node.js',
            'slug' => 'nodejs',
            'docker_image' => 'node:20-bookworm',
            'is_active' => true,
        ]);
        $wpTemplate = ContainerTemplate::query()->create([
            'name' => 'WordPress',
            'slug' => 'wordpress-node-catalog-'.uniqid(),
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
        ]);

        $nodeProduct = Product::query()->create([
            'name' => 'Node Application Hosting',
            'slug' => 'node-app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2200,
            'is_active' => true,
            'container_template_id' => $nodeTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);
        $wpProduct = Product::query()->create([
            'name' => 'WP Starter',
            'slug' => 'wp-node-catalog-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 1500,
            'is_active' => true,
            'container_template_id' => $wpTemplate->id,
            'provisioning_driver_key' => 'container',
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $catalog = $convert->applicationHostingCatalog('nodejs');

        $this->assertTrue($convert->productMatchesStack($nodeProduct->fresh('containerTemplate'), 'nodejs'));
        $this->assertFalse($convert->productMatchesStack($wpProduct->fresh('containerTemplate'), 'nodejs'));
        $this->assertTrue($catalog['recommended']->contains('id', $nodeProduct->id));
        $this->assertFalse($catalog['recommended']->contains('id', $wpProduct->id));
        $this->assertFalse($catalog['fallback']);
        $this->assertSame('Node.js', DirectAdminToContainerConvertService::stackLabel('nodejs'));
        $this->assertSame('nodejs', $convert->normalizeConvertibleStack(['stack' => 'nodejs']));
    }

    public function test_extra_sites_become_unbilled_siblings_on_the_same_package(): void
    {
        $template = ContainerTemplate::query()->create([
            'name' => 'Node.js',
            'slug' => 'nodejs',
            'docker_image' => 'node:20-bookworm',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'App Hosting Silver',
            'slug' => 'app-hosting-silver-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2500,
            'is_active' => true,
            'container_template_id' => $template->id,
            'provisioning_driver_key' => 'container',
        ]);
        $user = User::factory()->create();
        $anchor = Service::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'name' => 'sigtuna.org',
            'status' => 'provisioning',
            'billing_cycle' => 'annual',
            'next_due_date' => now()->addYear(),
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'domain' => 'sigtuna.org',
                'da_legacy' => ['stack' => 'nodejs'],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $attached = $convert->attachConvertProject(
            $anchor,
            $product,
            [
                [
                    'domain' => 'app.sigtuna.org',
                    'stack' => 'nodejs',
                    'docroot' => '/home/sigtunaco/domains/app.sigtuna.org/public_html',
                    'app_root' => '/home/sigtunaco/domains/app.sigtuna.org/public_html',
                    'has_wp_config' => false,
                    'is_primary' => false,
                ],
                [
                    'domain' => 'blog.sigtuna.org',
                    'stack' => 'wordpress',
                    'docroot' => '/home/sigtunaco/domains/blog.sigtuna.org/public_html',
                    'app_root' => '/home/sigtunaco/domains/blog.sigtuna.org/public_html',
                    'has_wp_config' => true,
                    'is_primary' => false,
                ],
            ],
            99,
            'sigtunaco',
            0.3333,
            [
                ['name' => 'sigtunaco_db1'],
                ['name' => 'sigtunaco_db2'],
            ],
        );

        $this->assertCount(2, $attached['sibling_ids']);
        $anchor->refresh();
        $this->assertNotNull($anchor->project_id);
        $this->assertSame($anchor->id, $attached['project']->billing_service_id);

        $sibling = Service::query()->find($attached['sibling_ids'][0]);
        $this->assertSame($product->id, $sibling->product_id);
        $this->assertSame(['sigtunaco_db1', 'sigtunaco_db2'], array_column($sibling->service_meta['da_legacy']['databases'] ?? [], 'name'));
        $this->assertSame(0.0, (float) $sibling->custom_price);
        $this->assertFalse((bool) $sibling->service_meta['project_billing_anchor']);
        $this->assertSame(DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY, $sibling->service_meta['project_recipe']);
        $this->assertTrue(app(ProjectRecipeService::class)->shouldSkipRenewalInvoice($sibling->service_meta));

        $extras = $convert->extraConvertibleSites([
            'domain' => 'sigtuna.org',
            'sites' => [
                ['domain' => 'sigtuna.org', 'is_primary' => true],
                ['domain' => 'app.sigtuna.org', 'is_primary' => false],
            ],
        ]);
        $this->assertCount(1, $extras);
        $this->assertSame('app.sigtuna.org', $extras[0]['domain']);

        $withoutFlag = $convert->extraConvertibleSites([
            'domain' => 'sigtuna.org',
            'sites' => [
                ['domain' => 'sigtuna.org'],
                ['domain' => 'theharbor.co.ke'],
            ],
        ]);
        $this->assertCount(1, $withoutFlag);
        $this->assertSame('theharbor.co.ke', $withoutFlag[0]['domain']);
        $this->assertSame('nodejs', $convert->templateSlugForDetectedStack('nodejs', $product));
    }

    public function test_database_export_warnings_surface_nodejs_and_missing_api_inventory(): void
    {
        $convert = app(DirectAdminToContainerConvertService::class);

        $warnings = $convert->databaseExportWarnings('nodejs', [
            'databases' => [['name' => 'sigtunaco_db1']],
            'account' => ['counts' => ['database' => 4]],
        ]);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Node.js', implode(' ', $warnings));

        $missing = $convert->databaseExportWarnings('laravel', [
            'databases' => [],
            'account' => ['counts' => ['database' => 3]],
        ]);

        $this->assertStringContainsString('CMD_API_DATABASES', implode(' ', $missing));
    }

    public function test_resolve_sibling_database_inventory_falls_back_to_anchor_legacy(): void
    {
        $anchor = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => Product::query()->create([
                'name' => 'App',
                'slug' => 'app-'.uniqid(),
                'type' => 'container_hosting',
                'monthly_price' => 1000,
                'is_active' => true,
                'provisioning_driver_key' => 'container',
            ])->id,
            'name' => 'sigtuna.org',
            'status' => 'provisioning',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'da_legacy' => [
                    'databases' => [['name' => 'sigtunaco_db1']],
                ],
            ],
        ]);

        $sibling = Service::query()->create([
            'user_id' => $anchor->user_id,
            'product_id' => $anchor->product_id,
            'name' => 'app.sigtuna.org',
            'status' => 'pending',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'source_service_id' => $anchor->id,
                'da_legacy' => ['stack' => 'nodejs'],
            ],
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $inventory = $convert->resolveSiblingDatabaseInventory($sibling, $sibling->service_meta['da_legacy']);

        $this->assertSame(['sigtunaco_db1'], array_column($inventory, 'name'));
    }

    public function test_customer_service_name_uses_converted_hostname_when_the_row_is_still_named_after_the_da_package(): void
    {
        $service = new Service([
            'name' => 'Silver',
            'service_meta' => ['domain' => 'sigtuna.org'],
        ]);

        $this->assertSame('sigtuna.org', $service->customerServiceName());
    }

    public function test_resolve_email_product_prefers_bundle_then_catalog(): void
    {
        $email = Product::query()->create([
            'name' => 'Business Email',
            'slug' => 'biz-email-'.uniqid(),
            'type' => 'email_hosting',
            'monthly_price' => 500,
            'is_active' => true,
            'provisioning_driver_key' => 'mailcow',
        ]);
        $template = ContainerTemplate::query()->create([
            'name' => 'Node.js',
            'slug' => 'nodejs-mail-'.uniqid(),
            'docker_image' => 'node:20-bookworm',
            'is_active' => true,
        ]);
        $container = Product::query()->create([
            'name' => 'App plus mail',
            'slug' => 'app-plus-mail-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2500,
            'is_active' => true,
            'container_template_id' => $template->id,
            'provisioning_driver_key' => 'container',
            'bundled_email_product_id' => $email->id,
        ]);

        $convert = app(DirectAdminToContainerConvertService::class);
        $this->assertSame($email->id, $convert->resolveEmailProductForConvert($container->fresh('bundledEmailProduct'))?->id);

        $override = Product::query()->create([
            'name' => 'Other Email',
            'slug' => 'other-email-'.uniqid(),
            'type' => 'email_hosting',
            'monthly_price' => 800,
            'is_active' => true,
            'provisioning_driver_key' => 'mailcow',
        ]);
        $this->assertSame($override->id, $convert->resolveEmailProductForConvert($container, $override)?->id);
    }

    public function test_mail_pull_with_no_mailboxes_is_a_noop(): void
    {
        $email = Product::query()->create([
            'name' => 'Business Email',
            'slug' => 'biz-email-empty-'.uniqid(),
            'type' => 'email_hosting',
            'monthly_price' => 500,
            'is_active' => true,
            'provisioning_driver_key' => 'mailcow',
        ]);
        $da = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => Product::query()->create([
                'name' => 'Silver DA',
                'slug' => 'silver-da-mail-'.uniqid(),
                'type' => 'shared_hosting',
                'monthly_price' => 1000,
                'is_active' => true,
                'provisioning_driver_key' => 'directadmin',
            ])->id,
            'name' => 'empty-mail',
            'status' => 'active',
            'billing_cycle' => 'annual',
        ]);

        $result = app(DirectAdminToMailcowMigrationService::class)
            ->pullFromDirectAdminUser($da, $email, []);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['created_mailboxes']);
        $this->assertSame(0, Service::query()->where('provisioning_driver_key', 'mailcow')->count());
    }

    public function test_template_slug_for_unknown_stack_prefers_static_site_and_must_exist(): void
    {
        $convert = app(DirectAdminToContainerConvertService::class);

        ContainerTemplate::query()->create([
            'name' => 'Static Website',
            'slug' => 'static-site',
            'docker_image' => 'nginx:alpine',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Shared App Hosting',
            'slug' => 'shared-app-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $this->assertSame('static-site', $convert->templateSlugForDetectedStack('unknown', $product));
        $this->assertSame('static-site', $convert->templateSlugForDetectedStack('static_or_php', $product));
    }

    public function test_infers_primary_stack_from_addon_site_when_primary_docroot_is_empty(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $inferred = $migrator->inferPrimaryStackFromAddonSites([
            [
                'domain' => 'christsummit.org',
                'stack' => 'unknown',
                'is_primary' => true,
            ],
            [
                'domain' => 'christosummit.org',
                'stack' => 'static_or_php',
                'is_primary' => false,
            ],
        ], 'christsummit.org');

        $this->assertNotNull($inferred);
        $this->assertSame('static_or_php', $inferred['stack']);
        $this->assertSame('christosummit.org', $inferred['inferred_from']);
    }

    public function test_classifies_static_site_from_script_and_style_assets(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/christos/domains/christosummit.org/public_html';

        $classified = $migrator->classifyDetectedMarkers(
            implode("\n", [
                'DIR:'.$docroot,
                'IDX:'.$docroot,
            ]),
            $docroot
        );

        $this->assertSame('static_or_php', $classified['stack']);
    }

    public function test_generic_tar_command_creates_empty_archive_when_docroot_is_missing(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/christos/domains/christsummit.org/public_html';
        $filesTar = '/opt/talksasa/da-migrations/site-export/files.tar.gz';

        $cmd = $migrator->buildGenericDocrootTarCommand($docroot, $filesTar, 'static_or_php');

        $this->assertStringContainsString('[ ! -d ', $cmd);
        $this->assertStringContainsString('mktemp -d', $cmd);
        $this->assertStringContainsString($filesTar, $cmd);
    }
}
