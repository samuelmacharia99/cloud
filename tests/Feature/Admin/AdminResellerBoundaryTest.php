<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationEvent;
use App\Enums\TicketHandledBy;
use App\Mail\GenericNotificationMail;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailDeliveryService;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The places where an admin, not the system, presses Send. None of them may
 * reach a reseller's customer: not the SMS page, not login credentials, not
 * a service's credentials, and not any platform-branded mail underneath.
 */
class AdminResellerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reseller;

    private User $managed;

    private User $direct;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('mail_from_address', 'noreply@example.com');
        Setting::setValue('mail_from_name', 'Talksasa Cloud');

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();
        $this->reseller = User::factory()->create(['name' => 'Acme Hosting', 'phone' => '+254700000001']);
        $this->reseller->forceFill(['is_reseller' => true])->save();
        $this->managed = User::factory()->create(['name' => 'Jane Client', 'reseller_id' => $this->reseller->id, 'phone' => '+254700000002']);
        $this->direct = User::factory()->create(['name' => 'Direct Customer', 'phone' => '+254700000003']);
    }

    #[Test]
    public function the_sms_page_lists_only_platform_customers(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.sms.index'))
            ->assertOk()
            ->assertSee('Direct Customer')
            ->assertDontSee('Jane Client')
            ->assertDontSee('Acme Hosting');
    }

    #[Test]
    public function an_sms_to_everyone_reaches_platform_customers_only(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['+254700000003'], 'Maintenance tonight')
            ->andReturn(['success' => true, 'message' => 'Sent to 1 recipient.']);
        $this->app->instance(SmsService::class, $sms);

        $this->actingAs($this->admin)
            ->post(route('admin.sms.send'), ['message' => 'Maintenance tonight', 'recipient_type' => 'all'])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    #[Test]
    public function a_reseller_customer_picked_by_id_is_dropped_and_the_admin_is_told(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldReceive('send')
            ->once()
            ->with(['+254700000003'], 'Hello')
            ->andReturn(['success' => true, 'message' => 'Sent to 1 recipient.']);
        $this->app->instance(SmsService::class, $sms);

        $this->actingAs($this->admin)
            ->post(route('admin.sms.send'), [
                'message' => 'Hello',
                'recipient_type' => 'custom',
                'recipients' => [$this->direct->id, $this->managed->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning', '1 selected customer(s) belong to resellers and were not messaged.');

        $sms->shouldNotHaveReceived('send', [['+254700000002'], 'Hello']);
    }

    #[Test]
    public function an_sms_aimed_only_at_reseller_customers_sends_nothing(): void
    {
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsService::class, $sms);

        $this->actingAs($this->admin)
            ->post(route('admin.sms.send'), [
                'message' => 'Hello',
                'recipient_type' => 'custom',
                'recipients' => [$this->managed->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    public function an_admin_impersonates_a_reseller_customer_like_any_other_platform_user(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.impersonate', $this->managed))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->managed);
        $this->assertSame($this->admin->id, session('impersonating'));
    }

    #[Test]
    public function the_admin_customer_page_hides_tickets_the_reseller_handles(): void
    {
        Ticket::create([
            'user_id' => $this->managed->id,
            'reseller_id' => $this->reseller->id,
            'title' => 'Reseller-handled title line',
            'description' => 'Handled by the reseller.',
            'priority' => 'low',
            'status' => 'open',
            'handled_by' => TicketHandledBy::Reseller->value,
        ]);
        Ticket::create([
            'user_id' => $this->managed->id,
            'reseller_id' => $this->reseller->id,
            'title' => 'Escalated title line',
            'description' => 'Escalated to the platform.',
            'priority' => 'low',
            'status' => 'open',
            'handled_by' => TicketHandledBy::Platform->value,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.customers.show', $this->managed))
            ->assertOk()
            ->assertSee('Escalated title line')
            ->assertDontSee('Reseller-handled title line');
    }

    #[Test]
    public function login_credentials_for_a_reseller_customer_are_not_emailed_by_the_platform(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)->put(route('admin.customers.update', $this->managed), [
            'name' => $this->managed->name,
            'email' => $this->managed->email,
            'country' => 'KE',
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
            'status' => 'active',
            'send_welcome_email' => '1',
        ]);

        $response->assertRedirect(route('admin.customers.show', $this->managed));
        $this->assertStringContainsString(
            'Jane Client is a customer of Acme Hosting',
            (string) session('success')
        );
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_reseller_customers_service_credentials_cannot_be_resent_by_the_platform(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->managed->id,
            'reseller_id' => $this->reseller->id,
            'product_id' => Product::factory()->create(['type' => 'shared_hosting'])->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'credentials' => json_encode(['username' => 'jane', 'password' => 'pw']),
        ]);
        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldNotReceive('notifySharedHostingCredentials');
            $mock->shouldNotReceive('notifyServerCredentials');
        });

        $this->actingAs($this->admin)
            ->from(route('admin.services.show', $service))
            ->post(route('admin.services.resend-credentials', $service))
            ->assertRedirect(route('admin.services.show', $service))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Jane Client is a customer of Acme Hosting'));
    }

    #[Test]
    public function platform_branded_mail_to_a_reseller_customer_is_refused_underneath_everything_else(): void
    {
        Mail::fake();

        $sent = app(EmailDeliveryService::class)->sendPlatformMailable(
            $this->managed->email,
            new GenericNotificationMail('Hello', 'Hello', 'From the platform'),
            'Hello',
            NotificationEvent::AdminBroadcast,
            $this->managed,
        );

        $this->assertFalse($sent);
        Mail::assertNothingSent();

        $this->assertTrue(app(EmailDeliveryService::class)->sendPlatformMailable(
            $this->direct->email,
            new GenericNotificationMail('Hello', 'Hello', 'From the platform'),
            'Hello',
            NotificationEvent::AdminBroadcast,
            $this->direct,
        ));
        Mail::assertSent(GenericNotificationMail::class);
    }
}
