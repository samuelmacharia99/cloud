<?php

namespace Database\Factories;

use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeFactory extends Factory
{
    protected $model = Node::class;

    public function definition(): array
    {
        $suffix = fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => 'Test Node '.$suffix,
            'hostname' => 'node-'.$suffix.'.test.local',
            'ip_address' => fake()->unique()->localIpv4(),
            'type' => 'container_host',
            'status' => 'online',
            'cpu_cores' => 4,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 0,
            'ram_used_gb' => 0,
            'storage_used_gb' => 0,
            'ssh_port' => '22',
            'verify_ssl' => true,
            'region' => 'test',
            'container_count' => 0,
            'is_active' => true,
        ];
    }

    public function containerHost(): static
    {
        return $this->state(fn () => [
            'type' => 'container_host',
            'is_active' => true,
        ]);
    }

    public function directAdmin(): static
    {
        return $this->state(fn () => [
            'type' => 'directadmin',
            'status' => 'offline',
            'cpu_cores' => 0,
            'ram_gb' => 0,
            'storage_gb' => 0,
            'da_port' => '2222',
            'ssh_port' => '2222',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'test-login-key',
            'ssh_username' => 'root',
            'ssh_password' => 'ssh-secret',
            'nameserver_1' => 'ns1.example.com',
            'nameserver_2' => 'ns2.example.com',
            'is_active' => true,
        ]);
    }
}
