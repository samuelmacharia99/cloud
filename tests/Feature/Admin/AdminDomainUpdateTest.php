<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDomainUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_rename_domain_on_edit_page(): void
    {
        $admin = User::factory()->admin()->create();
        DomainExtension::query()->firstOrCreate(
            ['extension' => '.org'],
            ['description' => 'ORG', 'enabled' => true]
        );

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'christsummit',
            'extension' => '.org',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.domains.update', $domain), [
                'name' => 'christosummit',
                'extension' => '.org',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'name' => 'christosummit',
            'extension' => '.org',
        ]);
    }

    public function test_admin_cannot_rename_domain_to_existing_name_and_extension(): void
    {
        $admin = User::factory()->admin()->create();
        DomainExtension::query()->firstOrCreate(
            ['extension' => '.org'],
            ['description' => 'ORG', 'enabled' => true]
        );

        Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'christosummit',
            'extension' => '.org',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addYear(),
        ]);

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'christsummit',
            'extension' => '.org',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.domains.edit', $domain))
            ->patch(route('admin.domains.update', $domain), [
                'name' => 'christosummit',
                'extension' => '.org',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.domains.edit', $domain))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'name' => 'christsummit',
        ]);
    }

    public function test_registrar_linked_rename_requires_local_confirmation(): void
    {
        $admin = User::factory()->admin()->create();
        DomainExtension::query()->firstOrCreate(
            ['extension' => '.org'],
            ['description' => 'ORG', 'enabled' => true]
        );

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'christsummit',
            'extension' => '.org',
            'status' => 'active',
            'type' => 'registration',
            'registrar_external_id' => 'christsummit.org',
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.domains.edit', $domain))
            ->patch(route('admin.domains.update', $domain), [
                'name' => 'christosummit',
                'extension' => '.org',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.domains.edit', $domain))
            ->assertSessionHasErrors('confirm_local_rename');

        $this->actingAs($admin)
            ->patch(route('admin.domains.update', $domain), [
                'name' => 'christosummit',
                'extension' => '.org',
                'status' => 'active',
                'confirm_local_rename' => '1',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHas('success');
    }
}
