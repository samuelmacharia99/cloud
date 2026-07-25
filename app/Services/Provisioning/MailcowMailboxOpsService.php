<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * In-panel mailbox operations (password, display name, out-of-office).
 */
class MailcowMailboxOpsService
{
    public const VACATION_FILTER_DESC = 'Talksasa Out of Office';

    public function __construct(
        private MailcowProvisioningService $provisioning,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function changePassword(Service $service, string $mailbox, string $password): array
    {
        $mailbox = $this->assertMailbox($service, $mailbox);
        $client = $this->provisioning->clientForService($service);

        $result = $client->editMailbox($mailbox, [
            'password' => $password,
            'password2' => $password,
            'force_pw_update' => '0',
        ]);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Could not update password.'];
        }

        return ['success' => true, 'message' => 'Password updated for '.$mailbox.'.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateDisplayName(Service $service, string $mailbox, string $name): array
    {
        $mailbox = $this->assertMailbox($service, $mailbox);
        $client = $this->provisioning->clientForService($service);

        $result = $client->editMailbox($mailbox, [
            'name' => $name,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Could not update display name.'];
        }

        return ['success' => true, 'message' => 'Display name updated.'];
    }

    /**
     * Enable or replace the Talksasa vacation sieve filter.
     *
     * @return array{success: bool, message: string}
     */
    public function enableVacation(Service $service, string $mailbox, string $subject, string $body, int $days = 1): array
    {
        $mailbox = $this->assertMailbox($service, $mailbox);
        $client = $this->provisioning->clientForService($service);

        $this->removeVacationFilters($client, $mailbox);

        $days = max(1, min(30, $days));
        $subject = str_replace(['\\', '"'], ['\\\\', '\\"'], $subject);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $escapedBody = str_replace(['\\', '"'], ['\\\\', '\\"'], $body);

        $script = "require [\"vacation\"];\nvacation :days {$days} :subject \"{$subject}\" \"{$escapedBody}\";\n";

        $result = $client->addFilter([
            'username' => $mailbox,
            'active' => '1',
            'script_desc' => self::VACATION_FILTER_DESC,
            'script_data' => $script,
            'filter_type' => 'prefilter',
        ]);

        if (! $result['success']) {
            Log::warning('Mailcow vacation filter create failed', [
                'mailbox' => $mailbox,
                'message' => $result['message'] ?? null,
            ]);

            return [
                'success' => false,
                'message' => 'Could not enable out-of-office: '.($result['message'] ?? 'unknown error'),
            ];
        }

        return ['success' => true, 'message' => 'Out-of-office enabled for '.$mailbox.'.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function disableVacation(Service $service, string $mailbox): array
    {
        $mailbox = $this->assertMailbox($service, $mailbox);
        $client = $this->provisioning->clientForService($service);
        $removed = $this->removeVacationFilters($client, $mailbox);

        if ($removed === 0) {
            return ['success' => true, 'message' => 'No out-of-office filter was active.'];
        }

        return ['success' => true, 'message' => 'Out-of-office disabled for '.$mailbox.'.'];
    }

    /**
     * @return array{active: bool, subject: ?string, body: ?string}
     */
    public function vacationStatus(Service $service, string $mailbox): array
    {
        try {
            $mailbox = $this->assertMailbox($service, $mailbox);
            $client = $this->provisioning->clientForService($service);
            $listed = $client->listFilters($mailbox);
            foreach ($listed['data'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $desc = (string) ($row['script_desc'] ?? '');
                $active = (string) ($row['script_name'] ?? $row['active'] ?? '') !== 'inactive'
                    && (($row['active'] ?? '1') == '1' || ($row['script_name'] ?? '') === 'active');

                if ($desc === self::VACATION_FILTER_DESC && $active) {
                    $script = (string) ($row['script_data'] ?? '');
                    $subject = null;
                    $body = null;
                    if (preg_match('/:subject\\s+"((?:\\\\.|[^"\\\\])*)"/', $script, $m)) {
                        $subject = stripcslashes($m[1]);
                    }
                    if (preg_match('/"((?:\\\\.|[^"\\\\])*)"\\s*;\\s*$/m', $script, $m)) {
                        $body = stripcslashes($m[1]);
                    }

                    return ['active' => true, 'subject' => $subject, 'body' => $body];
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return ['active' => false, 'subject' => null, 'body' => null];
    }

    private function assertMailbox(Service $service, string $mailbox): string
    {
        $mailbox = strtolower(trim($mailbox));
        $domain = $this->provisioning->domainForService($service);

        if (! str_ends_with($mailbox, '@'.$domain)) {
            throw new \InvalidArgumentException('Mailbox does not belong to this mail domain.');
        }

        return $mailbox;
    }

    private function removeVacationFilters(MailcowService $client, string $mailbox): int
    {
        $listed = $client->listFilters($mailbox);
        if (! $listed['success']) {
            return 0;
        }

        $ids = [];
        foreach ($listed['data'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $desc = (string) ($row['script_desc'] ?? '');
            $id = $row['id'] ?? null;
            if ($id !== null && $desc === self::VACATION_FILTER_DESC) {
                $ids[] = $id;
            }
        }

        if ($ids !== []) {
            $client->deleteFilters($ids);
        }

        return count($ids);
    }
}
