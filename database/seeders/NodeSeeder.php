<?php

namespace Database\Seeders;

use App\Models\Node;
use Illuminate\Database\Seeder;

class NodeSeeder extends Seeder
{
    public function run(): void
    {
        $nodes = [
            [
                'name' => 'US-East-01',
                'hostname' => 'us-east-01.internal',
                'ip_address' => '192.168.1.10',
                'type' => 'container_host',
                'status' => 'online',
                'region' => 'US-East',
                'datacenter' => 'DC1',
                'cpu_cores' => 16,
                'ram_gb' => 64,
                'storage_gb' => 500,
                'cpu_used' => 45,
                'ram_used_gb' => 32,
                'storage_used_gb' => 250,
                'ssh_port' => '22',
                'api_url' => 'https://api.us-east-01.internal',
                'verify_ssl' => true,
                'is_active' => true,
                'description' => 'Primary container hosting node for US East region',
            ],
            [
                'name' => 'US-West-01',
                'hostname' => 'us-west-01.internal',
                'ip_address' => '192.168.1.11',
                'type' => 'dedicated_server',
                'status' => 'online',
                'region' => 'US-West',
                'datacenter' => 'DC2',
                'cpu_cores' => 24,
                'ram_gb' => 128,
                'storage_gb' => 1000,
                'cpu_used' => 60,
                'ram_used_gb' => 85,
                'storage_used_gb' => 750,
                'ssh_port' => '22',
                'api_url' => 'https://api.us-west-01.internal',
                'verify_ssl' => true,
                'is_active' => true,
                'description' => 'High-performance dedicated server for US West',
            ],
            [
                'name' => 'EU-Central-01',
                'hostname' => 'eu-central-01.internal',
                'ip_address' => '192.168.1.12',
                'type' => 'load_balancer',
                'status' => 'online',
                'region' => 'EU-Central',
                'datacenter' => 'DC3',
                'cpu_cores' => 8,
                'ram_gb' => 32,
                'storage_gb' => 100,
                'cpu_used' => 30,
                'ram_used_gb' => 12,
                'storage_used_gb' => 25,
                'ssh_port' => '22',
                'api_url' => 'https://api.eu-central-01.internal',
                'verify_ssl' => true,
                'is_active' => true,
                'description' => 'Load balancer for EU Central region',
            ],
            [
                'name' => 'DB-Primary-01',
                'hostname' => 'db-primary-01.internal',
                'ip_address' => '192.168.1.20',
                'type' => 'database_server',
                'status' => 'online',
                'region' => 'US-East',
                'datacenter' => 'DC1',
                'cpu_cores' => 12,
                'ram_gb' => 96,
                'storage_gb' => 2000,
                'cpu_used' => 35,
                'ram_used_gb' => 72,
                'storage_used_gb' => 1200,
                'ssh_port' => '22',
                'api_url' => 'https://api.db-primary-01.internal',
                'verify_ssl' => true,
                'is_active' => true,
                'description' => 'Primary database server for production',
            ],
            [
                'name' => 'Container-02',
                'hostname' => 'container-02.internal',
                'ip_address' => '192.168.1.21',
                'type' => 'container_host',
                'status' => 'degraded',
                'region' => 'US-East',
                'datacenter' => 'DC1',
                'cpu_cores' => 16,
                'ram_gb' => 64,
                'storage_gb' => 500,
                'cpu_used' => 85,
                'ram_used_gb' => 55,
                'storage_used_gb' => 400,
                'ssh_port' => '22',
                'api_url' => 'https://api.container-02.internal',
                'verify_ssl' => true,
                'is_active' => true,
                'description' => 'Secondary container host - running high load',
            ],
        ];

        foreach ($nodes as $node) {
            Node::create($node);
        }
    }
}
