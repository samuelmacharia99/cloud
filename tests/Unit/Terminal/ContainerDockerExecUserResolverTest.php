<?php

namespace Tests\Unit\Terminal;

use App\Services\Terminal\ContainerDockerExecUserResolver;
use PHPUnit\Framework\TestCase;

class ContainerDockerExecUserResolverTest extends TestCase
{
    public function test_uses_www_data_for_php_runtime_stacks(): void
    {
        $this->assertSame('www-data', ContainerDockerExecUserResolver::execUser('laravel'));
        $this->assertSame('www-data', ContainerDockerExecUserResolver::execUser('php'));
        $this->assertSame('www-data', ContainerDockerExecUserResolver::execUser('wordpress'));
        $this->assertSame('-u \'www-data\' ', ContainerDockerExecUserResolver::execUserFlag('laravel'));
    }

    public function test_uses_container_default_user_for_node_and_other_stacks(): void
    {
        $this->assertNull(ContainerDockerExecUserResolver::execUser('nodejs'));
        $this->assertNull(ContainerDockerExecUserResolver::execUser('python'));
        $this->assertNull(ContainerDockerExecUserResolver::execUser('ruby'));
        $this->assertSame('', ContainerDockerExecUserResolver::execUserFlag('nodejs'));
        $this->assertSame('', ContainerDockerExecUserResolver::execUserFlag(null));
    }
}
