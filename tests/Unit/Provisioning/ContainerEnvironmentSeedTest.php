<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerEnvironmentSeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The checkout seed is the only copy of a customer's environment between
 * paying and deploying, which can be days. It must be unreadable in the
 * database, survive a round trip byte for byte, and vanish once the
 * deployment row owns the values.
 */
class ContainerEnvironmentSeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stored_values_are_ciphertext_in_meta_and_plaintext_on_read(): void
    {
        $service = Service::factory()->create(['service_meta' => ['keep' => 'me']]);
        $seed = new ContainerEnvironmentSeed;

        $seed->store($service, ['DB_PASSWORD' => 'p$ss', 'APP_ENV' => 'production']);

        $meta = $service->fresh()->service_meta;
        $this->assertSame('me', $meta['keep']);
        $this->assertArrayNotHasKey(ContainerEnvironmentSeed::LEGACY_META_KEY, $meta);
        $this->assertIsString($meta[ContainerEnvironmentSeed::META_KEY]);
        $this->assertStringNotContainsString('p$ss', $meta[ContainerEnvironmentSeed::META_KEY]);
        $this->assertSame(
            '{"DB_PASSWORD":"p$ss","APP_ENV":"production"}',
            Crypt::decryptString($meta[ContainerEnvironmentSeed::META_KEY])
        );
        $this->assertSame(['DB_PASSWORD' => 'p$ss', 'APP_ENV' => 'production'], $seed->read($service->fresh()));
    }

    #[Test]
    public function encode_normalises_form_input_and_returns_null_when_nothing_remains(): void
    {
        $seed = new ContainerEnvironmentSeed;

        $this->assertNull($seed->encode([]));
        $this->assertNull($seed->encode(['NESTED' => ['a' => 'b'], '' => 'blank key']));

        $encoded = $seed->encode(['PORT' => 3000, 'DEBUG' => true, 'QUIET' => false, ' SPACED ' => 'x']);
        $this->assertSame(
            ['PORT' => '3000', 'DEBUG' => '1', 'QUIET' => '', 'SPACED' => 'x'],
            json_decode(Crypt::decryptString((string) $encoded), true)
        );
    }

    #[Test]
    public function read_falls_back_to_the_legacy_plaintext_key_and_prefers_the_seed_when_both_exist(): void
    {
        $seed = new ContainerEnvironmentSeed;

        $legacyOnly = Service::factory()->create([
            'service_meta' => [ContainerEnvironmentSeed::LEGACY_META_KEY => ['API_URL' => 'http://old']],
        ]);
        $this->assertSame(['API_URL' => 'http://old'], $seed->read($legacyOnly));

        $both = Service::factory()->create([
            'service_meta' => [
                ContainerEnvironmentSeed::LEGACY_META_KEY => ['API_URL' => 'http://old'],
                ContainerEnvironmentSeed::META_KEY => $seed->encode(['API_URL' => 'http://new']),
            ],
        ]);
        $this->assertSame(['API_URL' => 'http://new'], $seed->read($both));
    }

    #[Test]
    public function store_replaces_a_legacy_plaintext_copy(): void
    {
        $seed = new ContainerEnvironmentSeed;
        $service = Service::factory()->create([
            'service_meta' => [ContainerEnvironmentSeed::LEGACY_META_KEY => ['OLD' => '1'], 'other' => 'kept'],
        ]);

        $seed->store($service, ['NEW' => '2']);

        $meta = $service->fresh()->service_meta;
        $this->assertArrayNotHasKey(ContainerEnvironmentSeed::LEGACY_META_KEY, $meta);
        $this->assertSame('kept', $meta['other']);
        $this->assertSame(['NEW' => '2'], $seed->read($service->fresh()));
    }

    #[Test]
    public function clear_removes_both_keys_and_leaves_the_rest_of_meta_alone(): void
    {
        $seed = new ContainerEnvironmentSeed;
        $service = Service::factory()->create([
            'service_meta' => [
                ContainerEnvironmentSeed::LEGACY_META_KEY => ['A' => '1'],
                ContainerEnvironmentSeed::META_KEY => $seed->encode(['A' => '1']),
                'domain' => 'example.com',
            ],
        ]);

        $seed->clear($service);

        $this->assertSame(['domain' => 'example.com'], $service->fresh()->service_meta);
        $this->assertSame([], $seed->read($service->fresh()));

        // Nothing to clear is not a write.
        $untouched = Service::factory()->create(['service_meta' => ['domain' => 'x.test']]);
        $before = $untouched->fresh()->updated_at;
        $this->travel(5)->minutes();
        $seed->clear($untouched);
        $this->assertTrue($before->equalTo($untouched->fresh()->updated_at));
    }

    #[Test]
    public function an_unreadable_seed_does_not_break_a_deploy(): void
    {
        $seed = new ContainerEnvironmentSeed;
        $service = Service::factory()->create([
            'service_meta' => [
                ContainerEnvironmentSeed::META_KEY => 'not-ciphertext-after-a-key-rotation',
                ContainerEnvironmentSeed::LEGACY_META_KEY => ['FALLBACK' => 'yes'],
            ],
        ]);

        $this->assertSame(['FALLBACK' => 'yes'], $seed->read($service));

        $service->update(['service_meta' => [ContainerEnvironmentSeed::META_KEY => 'garbage']]);
        $this->assertSame([], $seed->read($service->fresh()));
    }
}
