<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;

class FixNodeEncryptionCommand extends Command
{
    protected $signature = 'node:fix-encryption {--node-id= : The node ID to fix}';
    protected $description = 'Re-encrypt node credentials after APP_KEY change';

    public function handle(): int
    {
        $this->info('=== Node Credential Encryption Fixer ===');
        $this->newLine();

        $nodeId = $this->option('node-id');

        if ($nodeId) {
            $node = Node::find($nodeId);
            if (!$node) {
                $this->error("Node #{$nodeId} not found.");
                return self::FAILURE;
            }
            $this->fixNode($node);
        } else {
            $this->showAllNodes();
            $nodeId = $this->ask('Enter node ID to fix (or press Enter to skip)');
            if (empty($nodeId)) {
                return self::SUCCESS;
            }
            $node = Node::find($nodeId);
            if (!$node) {
                $this->error("Node not found.");
                return self::FAILURE;
            }
            $this->fixNode($node);
        }

        return self::SUCCESS;
    }

    private function showAllNodes(): void
    {
        $nodes = Node::all(['id', 'name', 'ip_address', 'type', 'ssh_username', 'ssh_password']);

        if ($nodes->isEmpty()) {
            $this->warn('No nodes found.');
            return;
        }

        $this->info('Available Nodes:');
        $this->newLine();

        foreach ($nodes as $node) {
            $passwordStatus = $node->ssh_password ? '🔒 encrypted' : '❌ not set';
            $this->line("  [{$node->id}] {$node->name} ({$node->ip_address}) - {$passwordStatus}");
        }
        $this->newLine();
    }

    private function fixNode(Node $node): void
    {
        $this->info("Node: {$node->name} ({$node->ip_address})");
        $this->info("Type: {$node->type}");
        $this->newLine();

        // Check what credentials need to be fixed
        $needsPassword = $node->type !== 'directadmin' && !$node->ssh_password;
        $needsKey = $node->type === 'directadmin' && !$node->da_login_key;

        if (!$needsPassword && !$needsKey) {
            $this->info("ℹ Credentials are already set. You can:");
            $this->line("  1. Leave as-is (if credentials are working)");
            $this->line("  2. Re-enter them to fix decryption issues");
            $this->newLine();
        }

        if ($node->type === 'directadmin') {
            $this->fixDirectAdminNode($node);
        } else {
            $this->fixContainerNode($node);
        }
    }

    private function fixContainerNode(Node $node): void
    {
        $username = $this->ask('SSH Username (e.g., root)', $node->ssh_username);

        // Keep asking for password until we get one
        do {
            $password = $this->secret('SSH Password (will be encrypted before storing)');

            if (empty($password)) {
                $this->warn('⚠️  Password cannot be empty. Please enter your SSH password.');
                $this->newLine();
            }
        } while (empty($password));

        // Confirm password
        $confirm = $this->secret('Confirm SSH Password');

        if ($password !== $confirm) {
            $this->error('❌ Passwords do not match. Please try again.');
            return;
        }

        $node->update([
            'ssh_username' => $username,
            'ssh_password' => $password,
        ]);

        $this->info('✓ SSH credentials updated and encrypted with current APP_KEY');
        $this->line("  Username: {$username}");
        $this->line('  Password: ' . str_repeat('•', strlen($password)) . ' (' . strlen($password) . ' chars)');
        $this->newLine();
        $this->info('You can now test the connection by clicking "Test Health"');
    }

    private function fixDirectAdminNode(Node $node): void
    {
        $this->info('Enter your DirectAdmin Login Key:');
        $this->line('(Go to DirectAdmin → Admin → Manage Administrators → Your Account → Generate Login Key)');
        $this->newLine();

        $loginKey = $this->secret('DirectAdmin Login Key (20-character string)');

        if (empty($loginKey)) {
            $this->warn('Login key is required.');
            return;
        }

        $node->update([
            'da_login_key' => $loginKey,
        ]);

        $this->info('✓ DirectAdmin login key updated and encrypted with current APP_KEY');
        $this->line('  Key: ' . substr($loginKey, 0, 5) . '...' . substr($loginKey, -5));
        $this->newLine();
        $this->info('You can now test the connection by clicking "Test Connection"');
    }
}
