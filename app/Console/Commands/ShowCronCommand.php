<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShowCronCommand extends Command
{
    protected $signature = 'cron:show-setup';
    protected $description = 'Display the cron command needed for the system scheduler';

    public function handle()
    {
        $phpBinary = PHP_BINARY;
        $basePath = base_path();
        $artisanPath = base_path('artisan');
        $logsPath = storage_path('logs/schedule.log');

        $cronCommand = sprintf(
            '* * * * * www-data %s %s/artisan schedule:run >> %s 2>&1',
            $phpBinary,
            $basePath,
            $logsPath
        );

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════════════════════════════╗');
        $this->info('║                    CRON SCHEDULER SETUP CONFIGURATION                              ║');
        $this->info('╚════════════════════════════════════════════════════════════════════════════════════╝');

        $this->newLine();
        $this->comment('📋 Server Environment Information:');
        $this->line('  Project Path:     ' . $basePath);
        $this->line('  PHP Binary:       ' . $phpBinary);
        $this->line('  Artisan Location: ' . $artisanPath);
        $this->line('  Log Location:     ' . $logsPath);

        $this->newLine();
        $this->comment('🔧 Cron Command to Add to Crontab:');
        $this->newLine();
        $this->line('  ' . $cronCommand);
        $this->newLine();

        $this->comment('📝 Instructions:');
        $this->line('  1. SSH into your server as root');
        $this->line('  2. Run: sudo crontab -u www-data -e');
        $this->line('  3. Copy the command above and paste it at the end of the crontab file');
        $this->line('  4. Save and exit the editor');
        $this->line('  5. Verify with: sudo crontab -u www-data -l');
        $this->line('  6. Monitor logs: tail -f ' . $logsPath);

        $this->newLine();
        $this->comment('✅ Verification:');
        $this->line('  • After adding to crontab, wait 1 minute');
        $this->line('  • Check if jobs are running: php artisan cron:check-node-health');
        $this->line('  • View dashboard: /admin/cron');
        $this->line('  • Check logs: ' . $logsPath);

        $this->newLine();
        $this->warn('⚠️  WITHOUT THIS CRON JOB, THE SCHEDULER WILL NOT RUN!');
        $this->newLine();

        return 0;
    }
}
