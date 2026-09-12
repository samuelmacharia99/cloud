<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerIsolationOptions;
use App\Services\Provisioning\ContainerIsolationPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The policy is the tenant boundary. Each test pins one thing a customer
 * stack on a shared host must never be able to do again: publish on the
 * public interface, share a network with another stack, or run with
 * capabilities a web workload does not need.
 */
class ContainerIsolationPolicyTest extends TestCase
{
    #[Test]
    public function every_published_port_is_bound_to_loopback(): void
    {
        $compose = ['services' => [
            'app' => ['image' => 'x', 'ports' => ['31010:9119', '31011:9120/udp', '9121', 8080]],
            'edge' => ['image' => 'y', 'ports' => [['target' => 8080, 'published' => 30123, 'protocol' => 'tcp']]],
            'pinned' => ['image' => 'z', 'ports' => ['10.0.0.5:31012:80', '[::1]:31013:80']],
        ]];

        (new ContainerIsolationPolicy)->apply($compose, 'app', 'user-1-service-10');

        $this->assertSame(
            ['127.0.0.1:31010:9119', '127.0.0.1:31011:9120/udp', '127.0.0.1::9121', '127.0.0.1::8080'],
            $compose['services']['app']['ports']
        );
        $this->assertSame('127.0.0.1', $compose['services']['edge']['ports'][0]['host_ip']);
        $this->assertSame(['10.0.0.5:31012:80', '[::1]:31013:80'], $compose['services']['pinned']['ports']);
    }

    #[Test]
    public function the_stack_gets_a_private_named_network_and_keeps_its_aliases(): void
    {
        $compose = [
            'services' => [
                'app' => ['image' => 'x', 'networks' => ['default']],
                'db' => ['image' => 'mysql:8', 'networks' => ['default' => ['aliases' => ['user-1-service-10-db']]]],
            ],
            'networks' => ['default' => ['name' => 'talksasa-net', 'external' => true]],
        ];

        (new ContainerIsolationPolicy)->apply($compose, 'app', 'user-1-service-10', '10.210.7.0/24');

        $this->assertSame([
            'name' => 'user-1-service-10-net',
            'driver' => 'bridge',
            'ipam' => ['config' => [['subnet' => '10.210.7.0/24']]],
        ], $compose['networks']['default']);
        $this->assertArrayNotHasKey('external', $compose['networks']['default']);
        $this->assertArrayNotHasKey(ContainerIsolationPolicy::SHARED_NETWORK_KEY, $compose['networks']);
        $this->assertSame(['user-1-service-10-db'], $compose['services']['db']['networks']['default']['aliases']);
        // A list-form networks key is promoted to the map form Compose needs for aliases.
        $this->assertSame(['default' => []], $compose['services']['app']['networks']);
        $this->assertSame('user-1-service-10-net', $compose[ContainerIsolationPolicy::MARKER_KEY]['network']);
        $this->assertSame('10.210.7.0/24', $compose[ContainerIsolationPolicy::MARKER_KEY]['subnet']);
    }

    #[Test]
    public function only_an_opted_in_app_service_joins_the_shared_bridge(): void
    {
        $compose = ['services' => [
            'user-2-service-3' => ['image' => 'hermes'],
            'db' => ['image' => 'postgres:16'],
        ]];

        (new ContainerIsolationPolicy)->apply($compose, 'user-2-service-3', 'user-2-service-3', null, 'hermes');

        $this->assertSame(
            ['name' => 'talksasa-net', 'external' => true],
            $compose['networks'][ContainerIsolationPolicy::SHARED_NETWORK_KEY]
        );
        $this->assertArrayHasKey(ContainerIsolationPolicy::SHARED_NETWORK_KEY, $compose['services']['user-2-service-3']['networks']);
        $this->assertArrayNotHasKey('networks', $compose['services']['db']);
        $this->assertTrue($compose[ContainerIsolationPolicy::MARKER_KEY]['shared_network']);

        // Re-rendering the same stack under a slug that does not opt in strips it again.
        (new ContainerIsolationPolicy)->apply($compose, 'user-2-service-3', 'user-2-service-3', null, 'laravel');
        $this->assertArrayNotHasKey(ContainerIsolationPolicy::SHARED_NETWORK_KEY, $compose['networks']);
        $this->assertArrayNotHasKey('networks', $compose['services']['user-2-service-3']);
    }

    #[Test]
    public function every_service_is_hardened_without_duplicating_what_a_template_already_set(): void
    {
        $compose = ['services' => [
            'app' => ['image' => 'x', 'security_opt' => ['no-new-privileges:true', 'seccomp=custom.json'], 'cap_drop' => ['net_raw']],
            'locked' => ['image' => 'y', 'cap_drop' => ['ALL'], 'pids_limit' => 64],
            'worker' => ['image' => 'z'],
        ]];

        (new ContainerIsolationPolicy)->apply($compose, 'app', 'stack');

        $this->assertSame(['no-new-privileges:true', 'seccomp=custom.json'], $compose['services']['app']['security_opt']);
        $this->assertSame(['NET_RAW', 'MKNOD', 'AUDIT_WRITE', 'SYS_CHROOT', 'SETFCAP'], $compose['services']['app']['cap_drop']);
        $this->assertSame(1024, $compose['services']['app']['pids_limit']);

        $this->assertSame(['ALL'], $compose['services']['locked']['cap_drop']);
        $this->assertSame(64, $compose['services']['locked']['pids_limit']);
        $this->assertSame(['no-new-privileges:true'], $compose['services']['locked']['security_opt']);

        $this->assertSame(ContainerIsolationOptions::DEFAULT_CAP_DROP, $compose['services']['worker']['cap_drop']);
        $this->assertArrayNotHasKey('cap_add', $compose['services']['worker']);
    }

    #[Test]
    public function a_template_can_be_granted_capabilities_back_and_its_own_process_ceiling(): void
    {
        $options = ContainerIsolationOptions::fromArray([
            'cap_overrides' => ['erpnext' => ['cap_add' => ['sys_chroot', 'SYS_PTRACE'], 'pids_limit' => 4096]],
        ]);
        $compose = ['services' => ['app' => ['image' => 'x'], 'db' => ['image' => 'y']]];

        (new ContainerIsolationPolicy($options))->apply($compose, 'app', 'stack', null, 'ERPNext');

        foreach (['app', 'db'] as $name) {
            $this->assertSame(['SYS_CHROOT', 'SYS_PTRACE'], $compose['services'][$name]['cap_add']);
            $this->assertNotContains('SYS_CHROOT', $compose['services'][$name]['cap_drop']);
            $this->assertContains('NET_RAW', $compose['services'][$name]['cap_drop']);
            $this->assertSame(4096, $compose['services'][$name]['pids_limit']);
        }
    }

    #[Test]
    public function is_current_needs_the_marker_and_the_substance(): void
    {
        $policy = new ContainerIsolationPolicy;
        $legacy = Yaml::dump([
            'services' => ['app' => ['image' => 'x', 'ports' => ['31010:8080']]],
            'networks' => ['default' => ['name' => 'talksasa-net', 'external' => true]],
        ], 10, 2);

        $this->assertFalse($policy->isCurrent($legacy));

        $current = $policy->applyToYaml($legacy, 'app', 'stack', '10.210.1.0/24');
        $this->assertTrue($policy->isCurrent($current));

        $parsed = Yaml::parse($current);

        $publicAgain = $parsed;
        $publicAgain['services']['app']['ports'] = ['31010:8080'];
        $this->assertFalse($policy->isCurrent(Yaml::dump($publicAgain, 10, 2)));

        $sharedAgain = $parsed;
        $sharedAgain['networks']['default'] = ['name' => 'talksasa-net', 'external' => true];
        $this->assertFalse($policy->isCurrent(Yaml::dump($sharedAgain, 10, 2)));

        $unhardened = $parsed;
        unset($unhardened['services']['app']['security_opt']);
        $this->assertFalse($policy->isCurrent(Yaml::dump($unhardened, 10, 2)));

        $olderVersion = $parsed;
        $olderVersion[ContainerIsolationPolicy::MARKER_KEY]['version'] = 'isolation-v0';
        $this->assertFalse($policy->isCurrent(Yaml::dump($olderVersion, 10, 2)));

        $this->assertFalse($policy->isCurrent("services: [\n  isolation-v1"));
    }

    #[Test]
    public function applying_to_yaml_is_idempotent_and_keeps_everything_else(): void
    {
        $policy = new ContainerIsolationPolicy;
        $legacy = Yaml::dump([
            'services' => [
                'app' => [
                    'image' => 'node:20',
                    'command' => ['npm', 'start'],
                    'volumes' => ['/opt/app:/app'],
                    'ports' => ['31010:3000'],
                    'mem_reservation' => '256M',
                ],
            ],
            'volumes' => ['app_data' => null],
            'networks' => ['default' => ['name' => 'talksasa-net', 'external' => true]],
            'x-talksasa-resource-policy' => ['version' => 'elastic-v1'],
        ], 10, 2);

        $once = $policy->applyToYaml($legacy, 'app', 'stack', '10.210.3.0/24');
        $twice = $policy->applyToYaml($once, 'app', 'stack', '10.210.3.0/24');

        $this->assertSame($once, $twice);
        $parsed = Yaml::parse($twice);
        $this->assertSame(['npm', 'start'], $parsed['services']['app']['command']);
        $this->assertSame(['/opt/app:/app'], $parsed['services']['app']['volumes']);
        $this->assertSame('256M', $parsed['services']['app']['mem_reservation']);
        $this->assertSame(['app_data' => null], $parsed['volumes']);
        $this->assertSame('elastic-v1', $parsed['x-talksasa-resource-policy']['version']);
        $this->assertSame('10.210.3.0/24', $policy->subnetFromYaml($twice));
        $this->assertNull($policy->subnetFromYaml($legacy));
    }

    #[Test]
    public function network_names_are_derived_only_from_safe_container_names(): void
    {
        $this->assertSame('user-1-service-10-net', ContainerIsolationPolicy::stackNetworkName('User-1-Service-10'));

        $this->expectException(\InvalidArgumentException::class);
        ContainerIsolationPolicy::stackNetworkName('bad name; rm -rf /');
    }

    #[Test]
    public function options_are_normalised_from_config(): void
    {
        $options = ContainerIsolationOptions::fromArray([
            'publish_bind_address' => ' 127.0.0.1 ',
            'pids_limit' => '512',
            'cap_drop' => ['net_raw', '', ' mknod '],
            'shared_network_slugs' => ['Ollama'],
            'cap_overrides' => ['Hermes' => ['cap_add' => ['net_admin']], 7 => ['ignored'], 'php' => 'not-an-array'],
        ]);

        $this->assertSame('127.0.0.1', $options->publishBindAddress);
        $this->assertSame(512, $options->pidsLimit);
        $this->assertSame(['NET_RAW', 'MKNOD'], $options->capDrop);
        $this->assertTrue($options->joinsSharedNetwork('ollama'));
        $this->assertFalse($options->joinsSharedNetwork('hermes'));
        $this->assertSame(['NET_ADMIN'], $options->forSlug('hermes')['cap_add']);
        $this->assertSame(['cap_add' => [], 'cap_drop' => ['NET_RAW', 'MKNOD'], 'pids_limit' => 512], $options->forSlug(null));

        $this->expectException(\InvalidArgumentException::class);
        new ContainerIsolationOptions(publishBindAddress: 'not-an-ip');
    }
}
