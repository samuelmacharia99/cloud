<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerDoctorService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Doctor probes run on the node. With ports bound to loopback the public
 * hostname is only reachable through nginx from outside, so a probe targets
 * the published port on 127.0.0.1 and tells the app which name it is asking as.
 */
class ContainerDoctorLoopbackProbeTest extends TestCase
{
    #[Test]
    public function body_probes_send_the_served_hostname_when_they_have_one(): void
    {
        $doctor = app(ContainerDoctorService::class);

        $command = $doctor->homepageBodyProbeCommand('http://127.0.0.1:31012', 'app.example.com');
        $this->assertStringContainsString("-H 'Host: app.example.com' 'http://127.0.0.1:31012'", $command);
        $this->assertStringContainsString('head -c 200000', $command);

        $this->assertStringNotContainsString('Host:', $doctor->homepageBodyProbeCommand('http://127.0.0.1:31012'));
    }

    #[Test]
    public function the_laravel_login_probe_targets_loopback(): void
    {
        $deployment = new ContainerDeployment(['assigned_port' => 31012]);
        $deployment->setRelation('domains', new Collection([
            new ContainerDomain(['domain' => 'app.example.com', 'status' => 'active']),
        ]));

        $this->assertSame('http://127.0.0.1:31012/home', app(ContainerDoctorService::class)->laravelLoginProbeUrl($deployment));
        $this->assertNull(app(ContainerDoctorService::class)->laravelLoginProbeUrl(new ContainerDeployment([])));
    }
}
