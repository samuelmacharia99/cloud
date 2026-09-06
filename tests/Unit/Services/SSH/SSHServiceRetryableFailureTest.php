<?php

namespace Tests\Unit\Services\SSH;

use App\Models\Node;
use App\Services\SSH\SSHService;
use ReflectionMethod;
use Tests\TestCase;

class SSHServiceRetryableFailureTest extends TestCase
{
    public function test_undefined_array_key_is_retryable(): void
    {
        $node = new Node([
            'ip_address' => '127.0.0.1',
            'ssh_port' => 22,
            'ssh_username' => 'root',
        ]);

        $service = SSHService::forNode($node);
        $method = new ReflectionMethod(SSHService::class, 'isRetryableSshFailure');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'Undefined array key 1'));
        $this->assertTrue($method->invoke($service, 'SSH exec returned false (session channel open or exec request failed)'));
        $this->assertTrue($method->invoke($service, 'Please close the channel (1) before trying to open it again'));
        $this->assertTrue($method->invoke($service, 'Error reading SSH identification string; are you sure you\'re connecting to an SSH server?'));
        $this->assertTrue($method->invoke($service, 'SSH connection failed to 88.99.104.138: Connection reset by peer'));
        $this->assertFalse($method->invoke($service, 'SSH authentication failed - invalid credentials or network issue'));
    }
}
