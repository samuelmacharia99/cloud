<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpCodeIgniterRuntimeHealer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpCodeIgniterRuntimeHealerTest extends TestCase
{
    #[Test]
    public function it_writes_encryption_key_and_public_base_url(): void
    {
        $healer = new PhpCodeIgniterRuntimeHealer;
        $env = "CI_ENVIRONMENT = production\nencryption.key =\napp.baseURL = http://localhost:8080/\n";

        $this->assertTrue($healer->encryptionKeyIsMissing($env));
        $this->assertTrue($healer->baseUrlLooksLocal($env));

        $healed = $healer->healEnv($env, 'https://roadtrip.digiworldmediasln.com');

        $this->assertMatchesRegularExpression('/^encryption\\.key=hex2bin:[0-9a-f]{64}$/m', $healed);
        $this->assertStringContainsString('app.baseURL=https://roadtrip.digiworldmediasln.com/', $healed);
        $this->assertStringNotContainsString('localhost:8080', $healed);
        $this->assertFalse($healer->encryptionKeyIsMissing($healed));
    }

    #[Test]
    public function it_leaves_a_real_key_and_public_url_alone(): void
    {
        $healer = new PhpCodeIgniterRuntimeHealer;
        $env = 'encryption.key=hex2bin:'.str_repeat('ab', 32)."\napp.baseURL=https://roadtrip.digiworldmediasln.com/\n";

        $this->assertSame($env, $healer->healEnv($env, 'https://other.example.com'));
    }
}
