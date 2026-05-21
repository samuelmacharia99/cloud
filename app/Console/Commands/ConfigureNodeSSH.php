<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;

class ConfigureNodeSSH extends Command
{
    protected $signature = 'node:configure-ssh
        {--node-id= : Specific node ID to configure}
        {--hostname= : Specific node hostname to configure}
        {--all : Configure all nodes without SSH credentials}';

    protected $description = 'Configure SSH credentials for container host nodes';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->configureAllNodes();
        }

        if ($this->option('node-id')) {
            return $this->configureNodeById($this->option('node-id'));
        }

        if ($this->option('hostname')) {
            return $this->configureNodeByHostname($this->option('hostname'));
        }

        // Interactive mode
        return $this->interactiveMode();
    }

    private function interactiveMode(): int
    {
        $nodes = Node::where('type', 'container_host')->get();

        if ($nodes->isEmpty()) {
            $this->error('No container host nodes found');
            return 1;
        }

        $this->info('Container Host Nodes:');
        foreach ($nodes as $node) {
            $status = ($node->ssh_username && ($node->ssh_password || $node->da_login_key)) ? '✓' : '✗';
            $this->line("  [{$status}] ID {$node->id}: {$node->hostname} ({$node->ip_address})");
        }

        $nodeId = $this->ask('Enter node ID to configure (or "all" to configure missing ones)');

        if ($nodeId === 'all') {
            return $this->configureAllNodes();
        }

        return $this->configureNodeById($nodeId);
    }

    private function configureAllNodes(): int
    {
        $nodes = Node::where('type', 'container_host')
            ->where(function ($q) {
                $q->whereNull('ssh_username')
                  ->orWhereNull('ssh_password');
            })
            ->get();

        if ($nodes->isEmpty()) {
            $this->info('All container nodes already have SSH credentials configured');
            return 0;
        }

        $this->info("Found {$nodes->count()} nodes without SSH credentials");

        foreach ($nodes as $node) {
            $this->line("\nConfiguring {$node->hostname}:");
            $this->configureNode($node);
        }

        $this->info('✓ SSH credentials configured for all nodes');
        return 0;
    }

    private function configureNodeById(string $nodeId): int
    {
        $node = Node::find($nodeId);

        if (!$node) {
            $this->error("Node with ID {$nodeId} not found");
            return 1;
        }

        if ($node->type !== 'container_host') {
            $this->error("Node {$node->hostname} is not a container host (type: {$node->type})");
            return 1;
        }

        $this->configureNode($node);
        return 0;
    }

    private function configureNodeByHostname(string $hostname): int
    {
        $node = Node::where('hostname', $hostname)->first();

        if (!$node) {
            $this->error("Node {$hostname} not found");
            return 1;
        }

        if ($node->type !== 'container_host') {
            $this->error("Node {$node->hostname} is not a container host (type: {$node->type})");
            return 1;
        }

        $this->configureNode($node);
        return 0;
    }

    private function configureNode(Node $node): int
    {
        $this->line("\nNode: {$node->hostname}");
        $this->line("IP: {$node->ip_address}");
        $this->line("SSH Port: {$node->ssh_port}");

        $username = $this->ask('SSH Username', $node->ssh_username ?: 'root');
        $node->ssh_username = $username;

        $this->line("\nSSH Authentication Method:");
        $this->line("  1. Password");
        $this->line("  2. Private Key");

        $method = $this->choice('Choose authentication method', ['Password', 'Private Key'], 0);

        if ($method === 'Password') {
            $password = $this->secret('SSH Password (will be encrypted)');
            if ($password) {
                $node->ssh_password = $password;
                $node->da_login_key = null; // Clear key if switching to password
            }
        } else {
            $this->line("\nPaste your SSH private key (end with a blank line):");
            $keyLines = [];
            while (true) {
                $line = $this->line('');
                if ($line === '') break;
                $keyLines[] = $line;
            }
            $key = implode("\n", $keyLines);
            if ($key) {
                $node->da_login_key = $key;
                $node->ssh_password = null; // Clear password if switching to key
            }
        }

        $node->save();

        $this->info("✓ SSH credentials saved for {$node->hostname}");

        // Test connection
        if ($this->confirm("Test SSH connection to {$node->hostname}?", true)) {
            return $this->testConnection($node);
        }

        return 0;
    }

    private function testConnection(Node $node): int
    {
        try {
            $this->info("Testing SSH connection...");

            $ssh = \App\Services\SSH\SSHService::forNode($node);
            $output = $ssh->exec("echo 'SSH connection successful'; uname -a", 10);

            $this->info("✓ SSH connection successful");
            $this->line("Output: " . trim($output));

            return 0;
        } catch (\Exception $e) {
            $this->error("✗ SSH connection failed: " . $e->getMessage());
            return 1;
        }
    }
}
