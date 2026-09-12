<?php

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use Monolog\Logger as Monolog;

/**
 * The bare minimum of Laravel a container-free unit test needs.
 *
 * Provisioning services log recovery steps, and the deployment model
 * encrypts its compose and environment at rest, so a test that never boots
 * the framework still needs somewhere for Log:: and Crypt:: to go. Call
 * bootBareFacades() from setUp() and tearDownBareFacades() from tearDown().
 */
trait BootsBareFacades
{
    protected function bootBareFacades(): void
    {
        $container = new Container;
        $container->instance('log', new Logger(new Monolog('testing', [new NullHandler])));
        $container->instance('encrypter', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        Facade::setFacadeApplication($container);
    }

    protected function tearDownBareFacades(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
