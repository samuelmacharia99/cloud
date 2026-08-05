<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationEvent;
use App\Jobs\SendAdminBroadcastEmailJob;
use App\Mail\GenericNotificationMail;
use App\Models\Email;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminBulkEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();

        return $admin->fresh();
    }

    private function platformCustomer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'is_admin' => false,
            'is_reseller' => false,
            'reseller_id' => null,
        ], $overrides));
    }

    private function enableSmtp(): void
    {
        Setting::setValue('smtp_host', 'smtp.test.local');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_username', 'user');
        Setting::setValue('smtp_password', 'secret');
        Setting::setValue('smtp_encryption', 'tls');
        Setting::setValue('mail_from_address', 'noreply@talksasa.test');
        Setting::setValue('mail_from_name', 'Talksasa');
        Setting::setValue('email_queue_enabled', 'false');
    }

    public function test_admin_can_view_compose_on_emails_index(): void
    {
        $admin = $this->admin();
        $this->platformCustomer(['name' => 'Alice Customer', 'email' => 'alice@example.com']);

        $response = $this->actingAs($admin)->get(route('admin.emails.index'));

        $response->assertOk();
        $response->assertSee('Compose broadcast');
        $response->assertSee('Alice Customer');
        $response->assertSee('5 seconds apart');
    }

    public function test_admin_queues_paced_broadcast_for_all_platform_customers(): void
    {
        Queue::fake();
        $this->enableSmtp();

        $admin = $this->admin();
        $a = $this->platformCustomer(['email' => 'a@example.com']);
        $b = $this->platformCustomer(['email' => 'b@example.com']);

        $reseller = User::factory()->create(['is_admin' => false, 'is_reseller' => true, 'reseller_id' => null]);
        $managed = $this->platformCustomer([
            'email' => 'managed@example.com',
            'reseller_id' => $reseller->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.emails.send'), [
            'subject' => 'Scheduled maintenance',
            'body' => "We will be offline Saturday.\nThanks.",
            'recipient_type' => 'all',
        ]);

        $response->assertRedirect(route('admin.emails.index'));
        $response->assertSessionHas('success');

        Queue::assertPushed(SendAdminBroadcastEmailJob::class, function (SendAdminBroadcastEmailJob $job) use ($a, $b, $managed, $admin) {
            return $job->index === 0
                && $job->subject === 'Scheduled maintenance'
                && $job->sentById === $admin->id
                && $job->userIds === [$a->id, $b->id]
                && ! in_array($managed->id, $job->userIds, true);
        });
    }

    public function test_broadcast_job_sends_one_email_then_queues_next_after_delay(): void
    {
        Mail::fake();
        Queue::fake();
        $this->enableSmtp();

        $admin = $this->admin();
        $a = $this->platformCustomer(['email' => 'a@example.com']);
        $b = $this->platformCustomer(['email' => 'b@example.com']);

        $job = new SendAdminBroadcastEmailJob(
            [$a->id, $b->id],
            'Hello',
            'Body text',
            0,
            $admin->id,
        );

        $job->handle(app(\App\Services\EmailDeliveryService::class));

        Mail::assertSent(GenericNotificationMail::class, 1);

        $this->assertDatabaseHas('emails', [
            'recipient' => 'a@example.com',
            'subject' => 'Hello',
            'event_key' => NotificationEvent::AdminBroadcast->value,
            'status' => 'sent',
            'user_id' => $a->id,
            'sent_by' => $admin->id,
        ]);
        $this->assertDatabaseMissing('emails', [
            'recipient' => 'b@example.com',
        ]);

        Queue::assertPushed(SendAdminBroadcastEmailJob::class, function (SendAdminBroadcastEmailJob $next) use ($a, $b, $admin) {
            if ($next->index !== 1
                || $next->userIds !== [$a->id, $b->id]
                || $next->sentById !== $admin->id
                || $next->delay === null
            ) {
                return false;
            }

            $secondsUntil = now()->diffInSeconds($next->delay, false);

            return $secondsUntil >= SendAdminBroadcastEmailJob::DELAY_SECONDS - 1
                && $secondsUntil <= SendAdminBroadcastEmailJob::DELAY_SECONDS + 1;
        });
    }

    public function test_admin_can_queue_selected_platform_customers_only(): void
    {
        Queue::fake();
        $this->enableSmtp();

        $admin = $this->admin();
        $selected = $this->platformCustomer(['email' => 'selected@example.com']);
        $this->platformCustomer(['email' => 'other@example.com']);

        $response = $this->actingAs($admin)->post(route('admin.emails.send'), [
            'subject' => 'Hello',
            'body' => 'Just you.',
            'recipient_type' => 'custom',
            'recipients' => [$selected->id],
        ]);

        $response->assertRedirect(route('admin.emails.index'));

        Queue::assertPushed(SendAdminBroadcastEmailJob::class, function (SendAdminBroadcastEmailJob $job) use ($selected) {
            return $job->userIds === [$selected->id];
        });
    }

    public function test_non_admin_cannot_send_bulk_email(): void
    {
        $customer = $this->platformCustomer();

        $response = $this->actingAs($customer)->post(route('admin.emails.send'), [
            'subject' => 'Nope',
            'body' => 'Nope',
            'recipient_type' => 'all',
        ]);

        $response->assertForbidden();
    }

    public function test_send_requires_smtp_configuration(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->platformCustomer(['email' => 'a@example.com']);

        $response = $this->actingAs($admin)->post(route('admin.emails.send'), [
            'subject' => 'Hello',
            'body' => 'World',
            'recipient_type' => 'all',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNothingPushed();
    }
}
