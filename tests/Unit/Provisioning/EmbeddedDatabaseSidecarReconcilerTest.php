<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\EmbeddedDatabaseSidecarReconciler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A template's bundled database is a placeholder shared by every customer.
 * Per deployment it must get a unique name, the real credentials, plan-sized
 * flags and a healthcheck, and it must never invent a secret.
 */
class EmbeddedDatabaseSidecarReconcilerTest extends TestCase
{
    #[Test]
    public function wordpress_mysql_and_ospos_db_get_the_same_per_deployment_shape(): void
    {
        $reconciler = new EmbeddedDatabaseSidecarReconciler;

        $credentials = ['database' => 'shop', 'user' => 'shop', 'password' => 'user-secret', 'root_password' => 'root-secret'];

        $wordpress = ['services' => ['app' => ['image' => 'wordpress'], 'mysql' => ['image' => 'mysql:8.0', 'container_name' => 'mysql']]];
        $reconciler->reconcile($wordpress, 'mysql', 'app', $credentials, 2048, 'WordPress deploy');

        $ospos = ['services' => ['app' => ['image' => 'talksasa/ospos:3.4.1'], 'db' => ['image' => 'mariadb:10.11', 'mem_limit' => '1g']]];
        $reconciler->reconcile($ospos, 'db', 'app', $credentials, 2048, 'Open Source POS deploy');

        $this->assertSame('app-mysql', $wordpress['services']['mysql']['container_name']);
        $this->assertSame('app-db', $ospos['services']['db']['container_name']);

        foreach ([[$wordpress, 'mysql'], [$ospos, 'db']] as [$compose, $key]) {
            $db = $compose['services'][$key];
            $this->assertSame([
                'MYSQL_DATABASE' => 'shop',
                'MYSQL_USER' => 'shop',
                'MYSQL_PASSWORD' => 'user-secret',
                'MYSQL_ROOT_PASSWORD' => 'root-secret',
            ], $db['environment']);
            $this->assertSame('always', $db['restart']);
            $this->assertArrayNotHasKey('mem_limit', $db);
            $this->assertSame('256M', $db['mem_reservation']);
            $this->assertSame([$db['container_name']], $db['networks']['default']['aliases']);
            $this->assertStringContainsString('mysqladmin ping -h 127.0.0.1', $db['healthcheck']['test'][1]);
            $this->assertContains('--max-connections=', array_map(fn ($flag) => preg_replace('/=\d+$/', '=', $flag), $db['command']));
            $this->assertSame([$key => ['condition' => 'service_started']], $compose['services']['app']['depends_on']);
            $this->assertSame('always', $compose['services']['app']['restart']);
        }
    }

    #[Test]
    public function flags_are_sized_from_the_plan(): void
    {
        $reconciler = new EmbeddedDatabaseSidecarReconciler;
        $credentials = ['database' => 'shop', 'user' => 'shop', 'password' => 'p', 'root_password' => 'r'];

        $small = ['services' => ['app' => [], 'db' => ['image' => 'mariadb:10.11']]];
        $reconciler->reconcile($small, 'db', 'app', $credentials, 1024);

        $large = ['services' => ['app' => [], 'db' => ['image' => 'mariadb:10.11']]];
        $reconciler->reconcile($large, 'db', 'app', $credentials, 8192);

        $this->assertNotSame($small['services']['db']['command'], $large['services']['db']['command']);
    }

    #[Test]
    public function a_missing_credential_is_refused_rather_than_invented(): void
    {
        $reconciler = new EmbeddedDatabaseSidecarReconciler;
        $compose = ['services' => ['app' => [], 'db' => ['image' => 'mariadb:10.11']]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Open Source POS deploy is missing the database root or user password before composing the db sidecar.');

        $reconciler->reconcile($compose, 'db', 'app', ['database' => 'shop', 'user' => 'shop', 'password' => '', 'root_password' => 'r'], null, 'Open Source POS deploy');
    }

    #[Test]
    public function a_template_without_that_service_is_left_alone(): void
    {
        $reconciler = new EmbeddedDatabaseSidecarReconciler;
        $compose = ['services' => ['app' => ['image' => 'x']]];

        $reconciler->reconcile($compose, 'db', 'app', ['database' => 'a', 'user' => 'b', 'password' => 'c', 'root_password' => 'd']);

        $this->assertSame(['services' => ['app' => ['image' => 'x']]], $compose);
    }
}
