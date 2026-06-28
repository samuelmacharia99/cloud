<?php

namespace Tests\Unit\Mail;

use App\Mail\DomainExpiryMail;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainExpiryMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_expiry_mail_renders_renew_link_without_missing_route(): void
    {
        $user = User::factory()->create();

        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'expires_at' => now()->addDays(7),
        ]);

        $html = (new DomainExpiryMail($domain, 7))->render();

        $this->assertStringContainsString('Renew Domain Now', $html);
        $this->assertStringContainsString(route('customer.domains.index'), $html);
        $this->assertStringNotContainsString('customer.domains.show', $html);
    }
}
