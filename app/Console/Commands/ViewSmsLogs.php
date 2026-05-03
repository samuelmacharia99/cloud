<?php

namespace App\Console\Commands;

use App\Models\SmsLog;
use Illuminate\Console\Command;

class ViewSmsLogs extends Command
{
    protected $signature = 'sms:logs {--status=* : Filter by status (sent, failed)} {--reseller= : Filter by reseller email} {--limit=20 : Number of records to show} {--failed : Show only failed SMS}';

    protected $description = 'View SMS delivery logs with filtering and debugging information';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $statusFilters = $this->option('status');
        $resellerEmail = $this->option('reseller');
        $failedOnly = $this->option('failed');

        $query = SmsLog::query()
            ->with('sentBy')
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($failedOnly) {
            $query->where('status', 'failed');
        } elseif (!empty($statusFilters)) {
            $query->whereIn('status', $statusFilters);
        }

        // Filter by reseller
        if ($resellerEmail) {
            $query->whereHas('sentBy', fn($q) => $q->where('email', $resellerEmail));
        }

        $logs = $query->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->info('No SMS logs found matching your criteria.');
            return 0;
        }

        // Display summary
        $this->info("\n📱 SMS Delivery Logs Summary\n");
        $this->line(str_repeat('=', 120));

        $total = $logs->count();
        $sent = $logs->where('status', 'sent')->count();
        $failed = $logs->where('status', 'failed')->count();

        $this->info("Total Records: <fg=cyan>{$total}</> | Sent: <fg=green>{$sent}</> | Failed: <fg=red>{$failed}</>");
        $this->line(str_repeat('=', 120));

        // Display logs in table format
        $headers = ['ID', 'Recipient', 'Status', 'Reseller', 'Sender ID', 'Sent At', 'Response'];
        $rows = [];

        foreach ($logs as $log) {
            $statusColor = $log->status === 'sent' ? 'green' : 'red';
            $response = $this->parseResponse($log->response);

            $rows[] = [
                $log->id,
                substr($log->recipient, -7), // Show last 7 digits
                "<fg={$statusColor}>" . strtoupper($log->status) . '</>',
                substr($log->sentBy?->email ?? 'Unknown', 0, 20),
                $log->sender_id,
                $log->created_at->format('Y-m-d H:i:s'),
                $response,
            ];
        }

        $this->table($headers, $rows);

        // Show detailed failed records
        $failedLogs = $logs->where('status', 'failed')->take(5);
        if ($failedLogs->isNotEmpty()) {
            $this->warn("\n⚠️  Failed SMS Details (showing up to 5):\n");
            foreach ($failedLogs as $log) {
                $this->line("<fg=red>ID {$log->id}:</> {$log->recipient}");
                $this->line("  Status: {$log->status}");
                $this->line("  Response: " . $this->formatResponse($log->response));
                $this->line('');
            }
        }

        // Show configuration check
        if ($resellerEmail) {
            $this->showConfigCheck($resellerEmail);
        }

        return 0;
    }

    private function parseResponse(?string $response): string
    {
        if (empty($response)) {
            return 'N/A';
        }

        try {
            $data = json_decode($response, true);
            if (isset($data['sms_id'])) {
                return substr($data['sms_id'], 0, 10) . '...';
            }
            if (isset($data['error'])) {
                return substr($data['error'], 0, 15) . '...';
            }
            return 'See details below';
        } catch (\Exception $e) {
            return 'Invalid JSON';
        }
    }

    private function formatResponse(?string $response): string
    {
        if (empty($response)) {
            return 'No response data';
        }

        try {
            $data = json_decode($response, true);
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            return $response;
        }
    }

    private function showConfigCheck(string $resellerEmail): void
    {
        $reseller = \App\Models\User::where('email', $resellerEmail)->first();
        if (!$reseller) {
            $this->error("\nReseller not found: {$resellerEmail}");
            return;
        }

        $this->info("\n🔧 Configuration Check for: {$reseller->email}\n");

        $smsSettings = app(\App\Services\ResellerSettingsService::class)->getSmsSettings($reseller);

        $checks = [
            'SMS Enabled' => $smsSettings['enabled'],
            'API Key Set' => !empty($smsSettings['api_key']),
            'Sender ID Set' => !empty($smsSettings['sender_id']),
        ];

        foreach ($checks as $check => $passed) {
            $status = $passed ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("{$status} {$check}");
        }

        if (!empty($smsSettings['sender_id'])) {
            $this->line("  Sender ID: <fg=cyan>{$smsSettings['sender_id']}</>");
        }
    }
}
