<?php

namespace Tests\Feature;

use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Impersonation used to be two independent session flags, which could not
 * describe an admin viewing a reseller who was viewing their own customer.
 * Both flags ended up set, every reader picked a different one to honour, and
 * leaving skipped a level. These cover the chain that broke.
 */
class ImpersonationStackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Platform Admin']);
    }

    private function reseller(string $name = 'Acme Reseller'): User
    {
        // The reseller routes sit behind reseller.limits and reseller.billing,
        // so a reseller without a live package never reaches the controller.
        $package = ResellerPackage::create([
            'name' => 'Starter '.uniqid(),
            'description' => 'Impersonation test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'name' => $name,
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function customerOf(User $reseller): User
    {
        return User::factory()->customer()->create([
            'name' => 'End Customer',
            'reseller_id' => $reseller->id,
        ]);
    }

    public function test_nested_impersonation_unwinds_one_level_at_a_time(): void
    {
        $admin = $this->admin();
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($admin)
            ->post(route('admin.resellers.impersonate', $reseller))
            ->assertRedirect();
        $this->assertAuthenticatedAs($reseller);

        $this->post(route('reseller.customers.impersonate', $customer))->assertRedirect();
        $this->assertAuthenticatedAs($customer);
        $this->assertSame(2, app(ImpersonationService::class)->depth());

        // First exit returns to the reseller, not all the way to the admin.
        $this->post(route('exit-impersonation'))->assertRedirect();
        $this->assertAuthenticatedAs($reseller);
        $this->assertSame(1, app(ImpersonationService::class)->depth());

        // Second exit returns to the admin and empties the trail.
        $this->post(route('exit-impersonation'))->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(0, app(ImpersonationService::class)->depth());
        $this->assertFalse(app(ImpersonationService::class)->isImpersonating());
    }

    public function test_every_exit_route_pops_only_one_level(): void
    {
        $admin = $this->admin();
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($admin)->post(route('admin.resellers.impersonate', $reseller));
        $this->post(route('reseller.customers.impersonate', $customer));

        // The customer layout historically posted to the admin route while a
        // reseller frame was on top. Whichever alias is used, one level pops.
        $this->post(route('admin.exit-impersonation'))->assertRedirect();

        $this->assertAuthenticatedAs($reseller);
        $this->assertSame(1, app(ImpersonationService::class)->depth());
    }

    public function test_banner_names_the_account_the_session_came_from(): void
    {
        $admin = $this->admin();
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($admin)->post(route('admin.resellers.impersonate', $reseller));
        $this->post(route('reseller.customers.impersonate', $customer));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Back to Acme Reseller')
            ->assertDontSee('Back to Platform Admin');

        $this->post(route('exit-impersonation'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Back to Platform Admin');
    }

    public function test_a_reseller_impersonating_alone_returns_to_itself(): void
    {
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($reseller)->post(route('reseller.customers.impersonate', $customer));
        $this->assertAuthenticatedAs($customer);

        $this->post(route('exit-impersonation'))->assertRedirect();
        $this->assertAuthenticatedAs($reseller);
    }

    public function test_legacy_session_flags_are_rebuilt_as_a_stack(): void
    {
        $admin = $this->admin();
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        // An operator already mid-chain when this shipped.
        $this->actingAs($customer);
        Session::put('impersonating', $admin->id);
        Session::put('impersonating_reseller', $reseller->id);
        Session::put('impersonating_user_id', $customer->id);

        $impersonation = app(ImpersonationService::class);
        $this->assertSame(2, $impersonation->depth());
        $this->assertSame($reseller->id, $impersonation->currentFrame()['actor_id']);

        // The old keys are consumed, not left behind to be read again.
        $this->assertFalse(Session::has('impersonating'));
        $this->assertFalse(Session::has('impersonating_reseller'));

        $this->post(route('exit-impersonation'))->assertRedirect();
        $this->assertAuthenticatedAs($reseller);
    }

    public function test_exit_is_refused_when_the_actor_no_longer_holds_its_role(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin)->post(route('admin.customers.impersonate', $customer));
        $this->assertAuthenticatedAs($customer);

        // The admin is demoted while the support session is still open.
        $admin->forceFill(['is_admin' => false])->save();

        $this->post(route('exit-impersonation'))->assertForbidden();

        $this->assertGuest();
        $this->assertFalse(Session::has(ImpersonationService::SESSION_KEY));
    }

    public function test_nesting_is_capped(): void
    {
        $service = app(ImpersonationService::class);
        $admin = $this->admin();
        $target = User::factory()->customer()->create();

        $frames = array_fill(0, ImpersonationService::MAX_DEPTH, [
            'actor_id' => $admin->id,
            'actor_role' => ImpersonationService::ROLE_ADMIN,
            'actor_name' => $admin->name,
            'target_id' => $target->id,
            'target_name' => $target->name,
            'origin_url' => null,
            'started_at' => now()->toIso8601String(),
        ]);
        Session::put(ImpersonationService::SESSION_KEY, $frames);

        $this->expectException(HttpException::class);
        $service->begin($admin, $target, ImpersonationService::ROLE_ADMIN);
    }

    public function test_an_admin_cannot_be_impersonated(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.customers.impersonate', $otherAdmin))
            ->assertNotFound();
    }

    public function test_a_reseller_cannot_impersonate_a_foreign_customer(): void
    {
        $reseller = $this->reseller();
        $foreign = $this->customerOf($this->reseller('Other Reseller'));

        $this->actingAs($reseller)
            ->post(route('reseller.customers.impersonate', $foreign))
            ->assertNotFound();

        $this->assertAuthenticatedAs($reseller);
    }

    public function test_logging_out_drops_the_whole_trail(): void
    {
        $admin = $this->admin();
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($admin)->post(route('admin.resellers.impersonate', $reseller));
        $this->post(route('reseller.customers.impersonate', $customer));

        $this->post(route('logout'));

        $this->assertGuest();
        $this->assertFalse(Session::has(ImpersonationService::SESSION_KEY));
    }

    public function test_exiting_without_a_trail_is_a_harmless_redirect(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->post(route('exit-impersonation'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_exit_returns_to_where_impersonation_started(): void
    {
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($reseller)->post(
            route('reseller.customers.impersonate', $customer),
            [],
            ['HTTP_REFERER' => route('reseller.customers.show', $customer)],
        );

        $this->post(route('exit-impersonation'))
            ->assertRedirect(route('reseller.customers.show', $customer));
    }

    public function test_a_foreign_origin_cannot_turn_exit_into_an_open_redirect(): void
    {
        $reseller = $this->reseller();
        $customer = $this->customerOf($reseller);

        $this->actingAs($reseller)->post(
            route('reseller.customers.impersonate', $customer),
            [],
            ['HTTP_REFERER' => 'https://evil.example.com/steal'],
        );

        $response = $this->post(route('exit-impersonation'));

        $response->assertRedirect();
        $this->assertStringStartsWith(url('/'), $response->headers->get('Location'));
        $this->assertStringNotContainsString('evil.example.com', (string) $response->headers->get('Location'));
    }
}
