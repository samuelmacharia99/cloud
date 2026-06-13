<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AdminAttentionService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AdminAttentionServiceTest extends TestCase
{
    public function test_mark_seen_persists_section_timestamp_and_clears_cache(): void
    {
        Cache::put('admin_attention_42', ['domain_orders_new' => 3], 60);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 42;
        $user->settings = [];

        $user->shouldReceive('forceFill')
            ->once()
            ->with(Mockery::on(fn (array $data) => isset($data['settings']['admin_seen']['domain_orders'])))
            ->andReturnSelf();

        $user->shouldReceive('save')->once();

        $service = new AdminAttentionService;
        $service->markSeen($user, 'domain_orders');

        $this->assertFalse(Cache::has('admin_attention_42'));
    }

    public function test_mark_seen_ignores_unknown_sections(): void
    {
        $this->expectNotToPerformAssertions();

        $user = Mockery::mock(User::class);
        $user->shouldNotReceive('save');

        $service = new AdminAttentionService;
        $service->markSeen($user, 'invalid_section');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
