<?php

namespace Tests\Unit\Services;

use App\Exceptions\ResellerBoundaryException;
use App\Models\User;
use App\Services\ResellerBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A reseller's customers are the reseller's. The platform never writes to
 * them directly, and every attempt to is refused in a way the admin can
 * read and the log can trace.
 */
class ResellerBoundaryServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function platform_customers_exclude_admins_resellers_and_reseller_managed_users(): void
    {
        $reseller = User::factory()->create(['name' => 'Acme Hosting']);
        $reseller->forceFill(['is_reseller' => true])->save();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $managed = User::factory()->create(['reseller_id' => $reseller->id]);
        $direct = User::factory()->create();

        $ids = (new ResellerBoundaryService)->platformCustomers()->pluck('id')->all();

        $this->assertContains($direct->id, $ids);
        $this->assertNotContains($managed->id, $ids);
        $this->assertNotContains($reseller->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    #[Test]
    public function contacting_a_reseller_managed_customer_is_refused_by_name(): void
    {
        $reseller = User::factory()->create(['name' => 'Acme Hosting']);
        $reseller->forceFill(['is_reseller' => true])->save();
        $managed = User::factory()->create(['name' => 'Jane Client', 'reseller_id' => $reseller->id]);
        $boundary = new ResellerBoundaryService;

        try {
            $boundary->assertPlatformMayContact($managed, 'email', 'login credentials');
            $this->fail('Expected the contact to be refused.');
        } catch (ResellerBoundaryException $e) {
            $this->assertStringContainsString('Jane Client is a customer of Acme Hosting', $e->getMessage());
            $this->assertStringContainsString('login credentials', $e->getMessage());
        }

        // A platform customer passes silently.
        $boundary->assertPlatformMayContact(User::factory()->create(), 'sms', 'a broadcast');
        $this->assertTrue(true);
    }

    #[Test]
    public function a_recipient_list_is_split_into_platform_customers_and_a_refused_count(): void
    {
        $reseller = User::factory()->create();
        $reseller->forceFill(['is_reseller' => true])->save();
        $managed = User::factory()->create(['reseller_id' => $reseller->id]);
        $direct = User::factory()->create();

        $partition = (new ResellerBoundaryService)->partitionPlatformRecipients([$direct->id, $managed->id, $reseller->id, $direct->id]);

        $this->assertSame([$direct->id], $partition['allowed']->pluck('id')->all());
        $this->assertSame(2, $partition['refused']);
        $this->assertSame(0, (new ResellerBoundaryService)->partitionPlatformRecipients([])['refused']);
    }
}
