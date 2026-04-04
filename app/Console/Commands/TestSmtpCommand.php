<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\Setting;

class TestSmtpCommand extends Command
{
    protected $signature = 'mail:test {email}';
    protected $description = 'Send a test email to verify SMTP configuration';

    public function handle()
    {
        $email = $this->argument('email');
        $fromName = Setting::getValue('mail_from_name', 'Talksasa Cloud');
        $fromAddress = Setting::getValue('mail_from_address', 'noreply@talksasa.cloud');

        try {
            Mail::raw(
                "This is a test email from Talksasa Cloud.\n\nIf you received this email, your SMTP settings are configured correctly!",
                function ($message) use ($email, $fromName, $fromAddress) {
                    $message->to($email)
                            ->from($fromAddress, $fromName)
                            ->subject('Talksasa Cloud - SMTP Test Email');
                }
            );

            $this->info("✓ Test email sent successfully to {$email}");
            $this->line("SMTP Configuration:");
            $this->line("  Host: " . Setting::getValue('smtp_host'));
            $this->line("  Port: " . Setting::getValue('smtp_port'));
            $this->line("  User: " . Setting::getValue('smtp_user'));
            $this->line("  From: {$fromName} <{$fromAddress}>");

            return 0;
        } catch (\Exception $e) {
            $this->error("✗ Failed to send test email: " . $e->getMessage());
            return 1;
        }
    }
}
