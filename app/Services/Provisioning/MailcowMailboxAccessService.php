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
 * Passwordless webmail SSO + delivery tests via Mailcow app passwords.
 */
class MailcowMailboxAccessService
{
    public const APP_PASSWORD_PREFIX = 'Talksasa console';

    public const SSO_CACHE_PREFIX = 'mailcow_sso:';

    public function __construct(
        private MailcowProvisioningService $provisioning,
    ) {}

    /**
     * Issue a one-time SSO token that auto-logs into SOGo for this mailbox.
     *
     * @return array{success: bool, message: string, token?: string, redirect?: string}
     */
    public function issueWebmailSso(Service $service, string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));
        $domain = $this->provisioning->domainForService($service);

        if (! str_ends_with($mailbox, '@'.$domain)) {
            return ['success' => false, 'message' => 'Mailbox does not belong to this mail domain.'];
        }

        $client = $this->provisioning->clientForService($service);
        $password = $this->createRotatedAppPassword($client, $mailbox, self::APP_PASSWORD_PREFIX.' SSO');

        if ($password === null) {
            return [
                'success' => false,
                'message' => 'Could not create a temporary webmail login. Ensure app passwords are enabled in Mailcow.',
            ];
        }

        $token = Str::random(48);
        Cache::put(self::SSO_CACHE_PREFIX.$token, [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
            'mailbox' => $mailbox,
            'password' => $password,
            'connect_url' => $client->sogoConnectUrl(),
        ], now()->addSeconds(90));

        return [
            'success' => true,
            'message' => 'Opening webmail…',
            'token' => $token,
            'redirect' => route('customer.services.email.mailboxes.sso', [
                'service' => $service,
                'token' => $token,
            ]),
        ];
    }

    /**
     * @return array{mailbox: string, password: string, connect_url: string}|null
     */
    public function consumeWebmailSso(Service $service, int $userId, string $token): ?array
    {
        $key = self::SSO_CACHE_PREFIX.$token;
        $payload = Cache::pull($key);

        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['service_id'] ?? 0) !== (int) $service->id
            || (int) ($payload['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $mailbox = (string) ($payload['mailbox'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $connectUrl = (string) ($payload['connect_url'] ?? '');

        if ($mailbox === '' || $password === '' || $connectUrl === '') {
            return null;
        }

        return [
            'mailbox' => $mailbox,
            'password' => $password,
            'connect_url' => $connectUrl,
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
