<?php

namespace Tests\Feature\Reseller;

use App\Mail\VerificationCodeMail;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthEmailService;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerMailService;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class WhiteLabelIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_listing_is_the_canonical_customer_plan_identity(): void
    {
        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $shell = Product::factory()->create([
            'name' => 'Reseller DirectAdmin Hosting (system)',
            'slug' => ResellerProvisionProductResolver::SHELL_PRODUCT_SLUG,
            'type' => 'shared_hosting',
        ]);
        $listing = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'product_id' => $shell->id,
            'name' => 'Business Hosting',
            'slug' => 'business-hosting',
            'type' => 'shared_hosting',
            'monthly_price' => 2500,
            'yearly_price' => 25000,
            'is_active' => true,
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $shell->id,
            'name' => 'Reseller DirectAdmin Hosting (system)',
            'service_meta' => ['reseller_product_id' => $listing->id, 'domain' => 'customer.test'],
        ])->fresh();

        $this->assertSame($listing->id, $service->reseller_product_id);
        $this->assertSame('Business Hosting', $service->customerPlanName());
        $this->assertSame('customer.test', $service->customerServiceName());
    }

    public function test_stale_non_reseller_owner_is_rejected(): void
    {
        $owner = User::factory()->create(['is_reseller' => false]);
        $customer = User::factory()->create(['reseller_id' => $owner->id]);

        $this->assertNull(app(ResellerBrandingResolver::class)->resellerForCustomer($customer));
    }

    public function test_reseller_mail_does_not_mutate_shared_branding_or_global_sender(): void
    {
        Mail::fake();
        $reseller = User::factory()->reseller()->create([
            'settings' => [
                'branding' => ['company_name' => 'Acme Hosting', 'portal_url' => 'https://portal.acme.test'],
                'smtp' => [
                    'enabled' => true,
                    'host' => 'smtp.acme.test',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => 'mailer',
                    'password' => 'secret',
                    'from_address' => 'support@acme.test',
                    'from_name' => 'Acme Hosting',
                ],
            ],
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $originalFrom = config('mail.from');
        View::share('emailBranding', ['company_name' => 'sentinel']);

        app(ResellerMailService::class)->sendToCustomer(
            $customer,
            new VerificationCodeMail($customer->name, '123456'),
        );

        $this->assertSame($originalFrom, config('mail.from'));
        $this->assertSame('sentinel', View::shared('emailBranding')['company_name']);
        $this->assertNull(Config::get('mail.mailers.reseller_smtp_'.$reseller->id));
        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo($customer->email));
    }

    public function test_managed_customer_auth_mail_never_falls_back_to_platform_smtp(): void
    {
        Mail::fake();
        Setting::setValue('smtp_host', 'smtp.platform.test');
        $reseller = User::factory()->reseller()->create(['settings' => []]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $sent = app(AuthEmailService::class)->sendPasswordReset($customer, 'reset-token');

        $this->assertFalse($sent);
        Mail::assertNothingSent();
    }

    public function test_customer_urls_use_the_reseller_portal_host(): void
    {
        $reseller = User::factory()->reseller()->create([
            'settings' => [
                'branding' => ['custom_domain' => 'portal.acme.test'],
            ],
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $url = app(ResellerBrandingResolver::class)
            ->customerUrl($customer, 'password.reset', ['token' => 'abc', 'email' => $customer->email]);

        $this->assertStringStartsWith('https://portal.acme.test/', $url);
        $this->assertStringContainsString('/reset-password/abc', $url);
    }
}
