<?php

namespace Tests\Feature\Security;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\EmailVerificationCode;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_restore_or_delete_another_services_backup(): void
    {
        $user = User::factory()->create();
        [$service, $deployment] = $this->containerServiceFor($user);
        [$otherService, $otherDeployment] = $this->containerServiceFor($user);
        $backup = ContainerBackup::factory()->create([
            'service_id' => $otherService->id,
            'container_deployment_id' => $otherDeployment->id,
        ]);

        $this->actingAs($user)
            ->post(route('customer.services.container.backups.restore', [$service, $backup]))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('customer.services.container.backups.delete', [$service, $backup]))
            ->assertNotFound();
    }

    public function test_hashed_and_legacy_verification_codes_are_supported(): void
    {
        $hashedUser = User::factory()->unverified()->create();
        EmailVerificationCode::create([
            'user_id' => $hashedUser->id,
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->post('/verify-email-code', ['email' => $hashedUser->email, 'code' => '123456'])
            ->assertRedirect(route('dashboard'));

        auth()->logout();
        $legacyUser = User::factory()->unverified()->create();
        EmailVerificationCode::create([
            'user_id' => $legacyUser->id,
            'code' => '654321',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->post('/verify-email-code', ['email' => $legacyUser->email, 'code' => '654321'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_verification_responses_do_not_enumerate_accounts(): void
    {
        $user = User::factory()->unverified()->create();

        $unknown = $this->from('/verify-email-code')->post('/verify-email-code', [
            'email' => 'missing@example.test',
            'code' => '000000',
        ]);
        $known = $this->from('/verify-email-code')->post('/verify-email-code', [
            'email' => $user->email,
            'code' => '000000',
        ]);

        $this->assertSame(
            $unknown->getSession()->get('errors')->first('code'),
            $known->getSession()->get('errors')->first('code')
        );

        $message = 'If an unverified account matches that email, a verification code will be sent.';
        $this->post('/resend-verification-code', ['email' => 'missing@example.test'])
            ->assertSessionHas('success', $message);
        $this->post('/resend-verification-code', ['email' => $user->email])
            ->assertSessionHas('success', $message);
    }

    public function test_webhooks_fail_closed_and_deploy_token_is_header_only(): void
    {
        $this->postJson('/webhooks/c2b')->assertForbidden();
        $this->postJson('/webhooks/email/bounce', [
            'message_id' => 'message-1',
        ])->assertUnauthorized();

        $user = User::factory()->create();
        [$service] = $this->containerServiceFor($user);
        $service->update([
            'service_meta' => [
                'auto_deploy_enabled' => true,
                'auto_deploy_secret_hash' => Hash::make('deploy-secret'),
            ],
        ]);

        $this->postJson(route('webhooks.containers.git-deploy', [
            'service' => $service,
            'token' => 'deploy-secret',
        ]))->assertUnauthorized();
    }

    public function test_exit_impersonation_is_post_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/exit-impersonation')
            ->assertMethodNotAllowed();
    }

    public function test_sensitive_settings_and_node_tokens_are_encrypted_and_hidden(): void
    {
        Setting::setValue('smtp_password', 'smtp-secret');
        $this->assertSame('smtp-secret', Setting::getValue('smtp_password'));
        $this->assertNotSame('smtp-secret', DB::table('settings')->where('key', 'smtp_password')->value('value'));

        $node = Node::factory()->create(['api_token' => 'node-secret']);
        $this->assertSame('node-secret', $node->api_token);
        $this->assertArrayNotHasKey('api_token', $node->toArray());
        $this->assertNotSame('node-secret', DB::table('nodes')->where('id', $node->id)->value('api_token'));
    }

    /**
     * @return array{Service, ContainerDeployment}
     */
    private function containerServiceFor(User $user): array
    {
        $node = Node::factory()->create(['type' => 'container_host', 'is_active' => true]);
        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return [$service, $deployment];
    }
}
