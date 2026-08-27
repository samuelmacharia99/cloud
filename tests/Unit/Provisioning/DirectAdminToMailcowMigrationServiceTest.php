<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\DirectAdminToMailcowMigrationService;
use Tests\TestCase;

class DirectAdminToMailcowMigrationServiceTest extends TestCase
{
    public function test_parse_virtual_passwd_lines_reads_local_parts(): void
    {
        $raw = <<<'TXT'
info:$1$hash:500:500::/home/winkairwaystrave/imap/winkairwaystraveladventure.co.ke/info:
admin:$1$hash:500:500::/home/winkairwaystrave/imap/winkairwaystraveladventure.co.ke/admin:
# comment
:empty
TXT;

        $items = app(DirectAdminToMailcowMigrationService::class)->parseVirtualPasswdLines(
            $raw,
            'winkairwaystraveladventure.co.ke'
        );

        $this->assertSame(['info', 'admin'], array_column($items, 'account'));
        $this->assertSame('info@winkairwaystraveladventure.co.ke', $items[0]['email']);
    }

    public function test_parse_virtual_passwd_name_lines_splits_domain(): void
    {
        $raw = "winkairwaystraveladventure.co.ke:info\nwinkairwaystraveladventure.co.ke:sales\n";

        $items = app(DirectAdminToMailcowMigrationService::class)->parseVirtualPasswdNameLines($raw);

        $this->assertSame(['info', 'sales'], array_column($items, 'account'));
        $this->assertSame('winkairwaystraveladventure.co.ke', $items[0]['domain']);
    }

    public function test_virtual_passwd_list_command_is_valid_bash(): void
    {
        $cmd = app(DirectAdminToMailcowMigrationService::class)->virtualPasswdListCommand([
            'winkairwaystraveladventure.co.ke',
        ]);

        $syntax = [];
        $code = 0;
        exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
        $this->assertSame(0, $code, implode("\n", $syntax));
        $this->assertStringContainsString('/etc/virtual/winkairwaystraveladventure.co.ke/passwd', $cmd);
        $this->assertStringContainsString('sudo -n cat', $cmd);
    }

    public function test_mailbox_map_from_emails_groups_by_domain(): void
    {
        $map = app(DirectAdminToMailcowMigrationService::class)->mailboxMapFromEmails([
            'info@winkairwaystraveladventure.co.ke',
            'admin@winkairwaystraveladventure.co.ke',
            'info@winkairwaystraveladventure.co.ke',
        ]);

        $this->assertCount(2, $map['winkairwaystraveladventure.co.ke']);
        $this->assertSame(['info', 'admin'], array_column($map['winkairwaystraveladventure.co.ke'], 'account'));
    }

    public function test_mailcow_mailbox_already_exists_detects_exist_errors(): void
    {
        $service = app(DirectAdminToMailcowMigrationService::class);

        $this->assertTrue($service->mailcowMailboxAlreadyExists(['success' => false, 'message' => 'Mailbox exists']));
        $this->assertFalse($service->mailcowMailboxAlreadyExists(['success' => false, 'message' => 'quota exceeded']));
    }

    public function test_virtual_passwd_hash_and_maildir_commands_are_valid_bash(): void
    {
        $service = app(DirectAdminToMailcowMigrationService::class);
        $cmds = [
            $service->updateVirtualPasswdHashCommand('winkairwaystraveladventure.co.ke', 'info', 'TempPass1!'),
            $service->locateDirectAdminMaildirCommand('winkairwaystrave', 'winkairwaystraveladventure.co.ke', 'info'),
            $service->extractMaildirIntoMailcowCommand('info@winkairwaystraveladventure.co.ke', '/tmp/mail.tgz'),
        ];

        foreach ($cmds as $cmd) {
            $syntax = [];
            $code = 0;
            exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
            $this->assertSame(0, $code, $cmd."\n".implode("\n", $syntax));
        }

        $this->assertStringContainsString('/etc/virtual/winkairwaystraveladventure.co.ke/passwd', $cmds[0]);
        $this->assertStringContainsString('/home/winkairwaystrave/imap/', $cmds[1]);
        $this->assertStringContainsString('dovecot-mailcow', $cmds[2]);
    }

    public function test_maildir_size_commands_are_valid_bash(): void
    {
        $service = app(DirectAdminToMailcowMigrationService::class);
        $cmds = [
            $service->maildirSizeCommand('/home/winkairwaystrave/imap/winkairwaystraveladventure.co.ke/info'),
            $service->remoteFileSizeCommand('/tmp/mail.tgz'),
        ];

        foreach ($cmds as $cmd) {
            $syntax = [];
            $code = 0;
            exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
            $this->assertSame(0, $code, $cmd."\n".implode("\n", $syntax));
        }
    }
}
