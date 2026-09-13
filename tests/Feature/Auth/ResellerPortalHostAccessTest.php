<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\AuthEmailService;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A reseller's custom domain signs in the reseller and the reseller's own
 * customers, nobody else. The platform host stays open to everyone.
 */
class ResellerPortalHostAccessTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'billing.acme.test';

    private User $reseller;

    private User $otherReseller;

    private User $managed;

    private User $foreignManaged;

    private User $direct;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['registration.min_submit_seconds' => 0]);
        // The password rule checks Have I Been Pwned; keep the suite offline.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $this->reseller = User::factory()->create(['name' => 'Acme Hosting']);
        $this->reseller->forceFill([
            'is_reseller' => true,
            'settings' => ['branding' => ['company_name' => 'Acme Hosting', 'custom_domain' => self::HOST]],
        ])->save();

        $this->otherReseller = User::factory()->create(['name' => 'Beta Hosting']);
        $this->otherReseller->forceFill(['is_reseller' => true])->save();

        $this->managed = User::factory()->create(['reseller_id' => $this->reseller->id]);
        $this->foreignManaged = User::factory()->create(['reseller_id' => $this->otherReseller->id]);
        $this->direct = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /**
     * A URL on the reseller's custom domain. The test client takes the host
     * from an absolute URL; withServerVariables cannot override it.
     */
    private function portal(string $path): string
    {
        return 'http://'.self::HOST.$path;
    }

    private function login(User $user, bool $onResellerHost): TestResponse
    {
        $uri = $onResellerHost ? $this->portal('/login') : '/login';

        return $this->post($uri, ['email' => $user->email, 'password' => 'password']);
    }

    #[Test]
    public function the_reseller_and_its_own_customers_sign_in_on_the_reseller_host(): void
    {
        $this->login($this->reseller, true)->assertRedirectContains('/dashboard');
        $this->assertAuthenticatedAs($this->reseller);

        $this->post('/logout');

        $this->login($this->managed, true)->assertRedirectContains('/dashboard');
        $this->assertAuthenticatedAs($this->managed);
    }

    #[Test]
    public function a_platform_customer_is_refused_on_the_reseller_host(): void
    {
        $this->login($this->direct, true)
            ->assertSessionHasErrors(['email' => 'This account is not registered on this portal. Sign in at the address you registered with.']);

        $this->assertGuest();
    }

    #[Test]
    public function another_resellers_customer_and_another_reseller_are_refused_on_the_reseller_host(): void
    {
        $this->login($this->foreignManaged, true)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->login($this->otherReseller, true)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function an_admin_is_refused_on_the_reseller_host(): void
    {
        $this->login($this->admin, true)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function the_platform_host_still_signs_in_everyone(): void
    {
        foreach ([$this->direct, $this->managed, $this->foreignManaged, $this->admin] as $user) {
            $this->login($user, false)->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    #[Test]
    public function verifying_a_foreign_account_on_the_reseller_host_activates_it_without_a_session(): void
    {
        $user = User::factory()->unverified()->create();
        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->post($this->portal('/verify-email-code'), ['email' => $user->email, 'code' => '123456'])
            ->assertRedirectContains('/login')
            ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'Email verified.'));

        $this->assertGuest();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function verifying_the_resellers_own_customer_on_the_reseller_host_signs_them_in(): void
    {
        $user = User::factory()->unverified()->create(['reseller_id' => $this->reseller->id]);
        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->post($this->portal('/verify-email-code'), ['email' => $user->email, 'code' => '123456'])
            ->assertRedirectContains('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_reset_link_for_a_foreign_account_is_not_sent_from_the_reseller_host(): void
    {
        $mail = Mockery::mock(AuthEmailService::class);
        $mail->shouldNotReceive('sendPasswordReset');
        $this->app->instance(AuthEmailService::class, $mail);

        $this->post($this->portal('/forgot-password'), ['email' => $this->direct->email])
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT));
    }

    #[Test]
    public function a_reset_link_for_the_resellers_own_customer_is_sent_from_the_reseller_host(): void
    {
        $mail = Mockery::mock(AuthEmailService::class);
        $mail->shouldReceive('sendPasswordReset')->once()->andReturn(true);
        $this->app->instance(AuthEmailService::class, $mail);

        $this->post($this->portal('/forgot-password'), ['email' => $this->managed->email])
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT));
    }

    #[Test]
    public function registering_on_the_reseller_host_creates_the_resellers_customer(): void
    {
        // No SMTP is configured here, so the verification code cannot be
        // delivered; the account is still recorded, which is what matters.
        $this->post($this->portal('/register'), $this->registrationPayload('new@acme-customer.test', withPhone: false));

        $user = User::where('email', 'new@acme-customer.test')->firstOrFail();
        $this->assertSame($this->reseller->id, $user->reseller_id);
    }

    #[Test]
    public function an_invite_for_another_reseller_does_not_override_the_host(): void
    {
        $this->withSession(['registration_reseller_id' => $this->otherReseller->id])
            ->post($this->portal('/register'), $this->registrationPayload('invited@acme-customer.test', withPhone: false));

        $user = User::where('email', 'invited@acme-customer.test')->firstOrFail();
        $this->assertSame($this->reseller->id, $user->reseller_id);
    }

    #[Test]
    public function registering_on_the_platform_host_without_an_invite_creates_a_platform_customer(): void
    {
        $this->post('/register', $this->registrationPayload('direct@platform.test'));

        $this->assertNull(User::where('email', 'direct@platform.test')->firstOrFail()->reseller_id);
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(string $email, bool $withPhone = true): array
    {
        // Platform signups capture a phone for SMS verification; reseller
        // signups must not send one.
        $phone = $withPhone ? ['phone' => '0712345678'] : [];

        return $phone + [
            'first_name' => 'Test',
            'last_name' => 'User',
            'country' => 'KE',
            'email' => $email,
            'password' => 'Sunlit-Harbour-4821!',
            'password_confirmation' => 'Sunlit-Harbour-4821!',
            'agree' => '1',
            'registration_token' => app(RegistrationGuardService::class)->makeFormToken(),
        ];
    }
}
