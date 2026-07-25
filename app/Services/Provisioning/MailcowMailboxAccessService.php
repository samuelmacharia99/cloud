<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Passwordless webmail SSO + delivery tests.
 *
 * Open mailbox uses a signed URL into Mailcow's talksasa-sogo-sso.php
 * (requires ALLOW_ADMIN_EMAIL_LOGIN + SSO secret = API token).
 * Delivery tests still use temporary Mailcow app passwords over SMTP.
 */
class MailcowMailboxAccessService
{
    public const APP_PASSWORD_PREFIX = 'Talksasa console';

    public const SSO_TTL_SECONDS = 90;

    public function __construct(
        private MailcowProvisioningService $provisioning,
    ) {}

    /**
     * Issue a one-time signed SSO URL that opens SOGo for this mailbox.
     *
     * @return array{success: bool, message: string, redirect?: string}
     */
    public function issueWebmailSso(Service $service, string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));
        $domain = $this->provisioning->domainForService($service);

        if (! str_ends_with($mailbox, '@'.$domain)) {
            return ['success' => false, 'message' => 'Mailbox does not belong to this mail domain.'];
        }

        $client = $this->provisioning->clientForService($service);
        $secret = $client->apiKey();

        if ($secret === '') {
            return [
                'success' => false,
                'message' => 'Mailcow API token is missing on this node.',
            ];
        }

        $exp = now()->addSeconds(self::SSO_TTL_SECONDS)->timestamp;
        $sig = hash_hmac('sha256', $mailbox.'|'.$exp, $secret);

        $redirect = $client->baseUrl().'/talksasa-sogo-sso.php?'.http_build_query([
            'mailbox' => $mailbox,
            'exp' => $exp,
            'sig' => $sig,
        ]);

        return [
            'success' => true,
            'message' => 'Opening webmail…',
            'redirect' => $redirect,
        ];
    }

    /**
     * Send a delivery test from a mailbox to an external address.
     *
     * @return array{success: bool, message: string}
     */
    public function sendDeliveryTest(Service $service, string $fromMailbox, string $toEmail): array
    {
        $fromMailbox = strtolower(trim($fromMailbox));
        $toEmail = strtolower(trim($toEmail));
        $domain = $this->provisioning->domainForService($service);

        if (! str_ends_with($fromMailbox, '@'.$domain)) {
            return ['success' => false, 'message' => 'From address must be a mailbox on '.$domain.'.'];
        }

        if (! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Enter a valid destination email address.'];
        }

        $client = $this->provisioning->clientForService($service);
        $appName = self::APP_PASSWORD_PREFIX.' delivery '.now()->format('His');
        $password = $this->createRotatedAppPassword($client, $fromMailbox, $appName, deleteMatchingPrefix: false);

        if ($password === null) {
            return [
                'success' => false,
                'message' => 'Could not create a temporary SMTP credential for the delivery test.',
            ];
        }

        $host = $client->mailHostname();
        $port = (int) config('mailcow.smtp_port', 587);

        try {
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                rawurlencode($fromMailbox),
                rawurlencode($password),
                $host,
                $port
            );

            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            $email = (new Email)
                ->from(new Address($fromMailbox, 'Talksasa delivery test'))
                ->to($toEmail)
                ->subject('Talksasa mail delivery test for '.$fromMailbox)
                ->text(
                    "This is a delivery test from Talksasa Cloud.\n\n".
                    "From: {$fromMailbox}\n".
                    'Sent at: '.now()->toIso8601String()."\n".
                    "If you received this, outbound SMTP for this mailbox is working.\n"
                );

            $mailer->send($email);
        } catch (\Throwable $e) {
            Log::warning('Mailcow delivery test failed', [
                'service_id' => $service->id,
                'from' => $fromMailbox,
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            $this->deleteAppPasswordsByNamePrefix($client, $fromMailbox, $appName);

            return [
                'success' => false,
                'message' => 'Delivery test failed: '.$e->getMessage(),
            ];
        }

        $this->deleteAppPasswordsByNamePrefix($client, $fromMailbox, $appName);

        return [
            'success' => true,
            'message' => 'Test message sent to '.$toEmail.'. Check that inbox (and spam).',
        ];
    }

    private function createRotatedAppPassword(
        MailcowService $client,
        string $mailbox,
        string $appName,
        bool $deleteMatchingPrefix = true
    ): ?string {
        if ($deleteMatchingPrefix) {
            $this->deleteAppPasswordsByNamePrefix($client, $mailbox, self::APP_PASSWORD_PREFIX);
        }

        $password = Str::password(24, symbols: false);
        $created = $client->addAppPassword($mailbox, $appName, $password);

        if (! $created['success']) {
            Log::warning('Mailcow app password create failed', [
                'mailbox' => $mailbox,
                'message' => $created['message'] ?? null,
            ]);

            return null;
        }

        return $password;
    }

    private function deleteAppPasswordsByNamePrefix(MailcowService $client, string $mailbox, string $prefix): void
    {
        $listed = $client->listAppPasswords($mailbox);
        if (! $listed['success']) {
            return;
        }

        $ids = [];
        foreach ($listed['data'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['app_name'] ?? $row['name'] ?? '');
            $id = $row['id'] ?? $row['app_passwd_id'] ?? null;
            if ($id !== null && $name !== '' && str_starts_with($name, $prefix)) {
                $ids[] = $id;
            }
        }

        if ($ids !== []) {
            $client->deleteAppPasswords($ids);
        }
    }
}
