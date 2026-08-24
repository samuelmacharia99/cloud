<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Illuminate\Support\Facades\Log;

/**
 * DirectAdmin mailboxes → Mailcow: provision domains, recreate mailboxes, IMAP-pull mail.
 */
class DirectAdminToMailcowMigrationService
{
    public function __construct(
        private MailcowProvisioningService $mailcowProvisioning,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     mailboxes: list<array{account: string, email: string, domain: string}>,
     *     by_domain: array<string, list<array{account: string, email: string, domain: string}>>,
     *     domain: ?string,
     *     can_migrate: bool,
     *     blockers: list<string>,
     *     email_products: list<Product>
     * }
     */
    public function preflight(Service $daService): array
    {
        $blockers = [];

        if (! $daService->isSharedHosting()) {
            $blockers[] = 'Only DirectAdmin shared hosting services can migrate mail.';
        }

        $domain = strtolower(trim((string) (
            $daService->service_meta['domain']
            ?? $daService->service_meta['mailcow_domain']
            ?? ''
        )));

        $byDomain = [];
        $mailboxes = [];
        if ($daService->isSharedHosting() && $daService->node && $domain !== '') {
            $listed = $this->listMailboxesByDomains($daService, [$domain]);
            $byDomain = $listed['by_domain'];
            $mailboxes = $listed['all'];
            foreach ($listed['errors'] as $error) {
                $blockers[] = $error;
            }
        } elseif ($domain === '') {
            $blockers[] = 'No domain found on the DirectAdmin service meta.';
        }

        $mailNode = $this->mailcowProvisioning->resolveNode();
        if (! $mailNode) {
            $blockers[] = 'No active Mailcow node is available.';
        }

        $products = Product::query()
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->where('provisioning_driver_key', 'mailcow')
            ->orderBy('order')
            ->get();

        if ($products->isEmpty()) {
            $blockers[] = 'No active email_hosting products with mailcow driver.';
        }

        return [
            'success' => $blockers === [],
            'message' => $blockers === [] ? 'Ready to migrate mail to Mailcow.' : 'Resolve blockers before migrating.',
            'mailboxes' => $mailboxes,
            'by_domain' => $byDomain,
            'domain' => $domain !== '' ? $domain : null,
            'can_migrate' => $blockers === [],
            'blockers' => $blockers,
            'email_products' => $products->all(),
        ];
    }

    /**
     * @param  list<string>  $domains
     * @return array{
     *     by_domain: array<string, list<array{account: string, email: string, domain: string}>>,
     *     all: list<array{account: string, email: string, domain: string}>,
     *     errors: list<string>
     * }
     */
    public function listMailboxesByDomains(Service $daService, array $domains): array
    {
        $daService->loadMissing('node');
        $username = (string) ($daService->getHostingCredentials()['username']
            ?? $daService->service_meta['username']
            ?? $daService->external_reference
            ?? '');
        $byDomain = [];
        $all = [];
        $errors = [];

        if ($username === '' || ! $daService->node) {
            return [
                'by_domain' => [],
                'all' => [],
                'errors' => ['Missing DirectAdmin username or node for mailbox inventory.'],
            ];
        }

        $api = DirectAdminCustomerPanelApi::forServiceNode($daService->node);

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '' || ! str_contains($domain, '.')) {
                continue;
            }

            try {
                $listed = $api->listEmailAccounts($username, $domain);
            } catch (\Throwable $e) {
                if ($this->isUnownedDirectAdminDomain($e->getMessage())) {
                    continue;
                }
                $errors[] = $domain.': '.$e->getMessage();

                continue;
            }

            if (! ($listed['success'] ?? false)) {
                $message = (string) ($listed['message'] ?? 'Failed to list mailboxes.');
                if ($this->isUnownedDirectAdminDomain($message)) {
                    continue;
                }
                $errors[] = $domain.': '.$message;

                continue;
            }

            $rows = [];
            foreach ($listed['data'] ?? [] as $row) {
                $account = is_array($row) ? (string) ($row['account'] ?? $row['user'] ?? '') : (string) $row;
                $account = trim($account);
                if ($account === '') {
                    continue;
                }
                $local = str_contains($account, '@') ? explode('@', $account, 2)[0] : $account;
                $email = is_array($row) && ! empty($row['email'])
                    ? (string) $row['email']
                    : $local.'@'.$domain;
                $item = [
                    'account' => $local,
                    'email' => $email,
                    'domain' => $domain,
                ];
                $rows[] = $item;
                $all[] = $item;
            }

            if ($rows !== []) {
                $byDomain[$domain] = $rows;
            }
        }

        return [
            'by_domain' => $byDomain,
            'all' => $all,
            'errors' => $errors,
        ];
    }

    /**
     * Create (or reuse) email_hosting service, provision Mailcow domain, create mailboxes, optional sync jobs.
     *
     * @param  array{product_id: int, create_sync_jobs?: bool, da_imap_host?: string, da_imap_password?: string, pull_mail?: bool, project_id?: int|null, bundled?: bool}  $options
     * @return array{success: bool, message: string, email_service?: Service, created_mailboxes?: list<string>, sync_jobs?: list<string>}
     */
    public function migrate(Service $daService, array $options): array
    {
        $preflight = $this->preflight($daService);
        if (! $preflight['can_migrate']) {
            return [
                'success' => false,
                'message' => implode(' ', $preflight['blockers']),
            ];
        }

        $product = Product::findOrFail((int) $options['product_id']);

        return $this->pullFromDirectAdminUser(
            $daService,
            $product,
            $preflight['by_domain'] !== []
                ? $preflight['by_domain']
                : [(string) $preflight['domain'] => $preflight['mailboxes']],
            $options,
        );
    }

    /**
     * Provision Mailcow for every DA domain that has mail, recreate mailboxes, and IMAP-pull contents.
     *
     * @param  array<string, list<array{account: string, email?: string, domain?: string}>>  $byDomain
     * @param  array{create_sync_jobs?: bool, da_imap_host?: string, da_imap_password?: string, pull_mail?: bool, project_id?: int|null, bundled?: bool}  $options
     * @return array{success: bool, message: string, email_service?: Service, created_mailboxes?: list<string>, sync_jobs?: list<string>, failed_mailboxes?: list<string>}
     */
    public function pullFromDirectAdminUser(
        Service $daService,
        Product $product,
        array $byDomain,
        array $options = [],
    ): array {
        if ($product->type !== 'email_hosting') {
            return ['success' => false, 'message' => 'Selected product is not email hosting.'];
        }

        $byDomain = array_filter($byDomain, fn ($rows) => is_array($rows) && $rows !== []);
        if ($byDomain === []) {
            return [
                'success' => true,
                'message' => 'No DirectAdmin mailboxes to pull.',
                'created_mailboxes' => [],
                'sync_jobs' => [],
            ];
        }

        $daService->loadMissing('node', 'user');
        $primaryDomain = strtolower(trim((string) (
            $daService->service_meta['domain']
            ?? $daService->attachedDomainName()
            ?? array_key_first($byDomain)
        )));
        if (! isset($byDomain[$primaryDomain])) {
            $primaryDomain = (string) array_key_first($byDomain);
        }

        $bundled = (bool) ($options['bundled'] ?? false);
        $pullMail = array_key_exists('pull_mail', $options)
            ? (bool) $options['pull_mail']
            : true;
        $imapHost = (string) ($options['da_imap_host'] ?? $daService->node?->hostname ?? '');
        $sharedImapPassword = (string) ($options['da_imap_password'] ?? '');
        $projectId = isset($options['project_id']) ? (int) $options['project_id'] : (int) ($daService->project_id ?? 0);

        $emailService = Service::query()
            ->where('user_id', $daService->user_id)
            ->where('provisioning_driver_key', 'mailcow')
            ->where(function ($q) use ($primaryDomain) {
                $q->where('external_reference', $primaryDomain)
                    ->orWhere('service_meta->mailcow_domain', $primaryDomain);
            })
            ->first();

        $extraDomains = array_values(array_filter(
            array_keys($byDomain),
            fn (string $name) => $name !== $primaryDomain
        ));

        if (! $emailService) {
            $emailService = Service::create([
                'user_id' => $daService->user_id,
                'reseller_id' => $daService->reseller_id,
                'product_id' => $product->id,
                'project_id' => $projectId > 0 ? $projectId : null,
                'name' => 'Email: '.$primaryDomain,
                'status' => ServiceStatus::Pending,
                'billing_cycle' => $daService->billing_cycle ?? 'monthly',
                'custom_price' => $bundled ? 0 : null,
                'next_due_date' => $daService->next_due_date,
                'provisioning_driver_key' => 'mailcow',
                'service_meta' => [
                    'mailcow_domain' => $primaryDomain,
                    'domain' => $primaryDomain,
                    'additional_mail_domains' => $extraDomains,
                    'migrated_from_service_id' => $daService->id,
                    'email_domain_mode' => $bundled ? 'bundled_with_container' : 'migrated_from_directadmin',
                    'project_recipe' => $projectId > 0 ? 'da_convert' : null,
                    'project_role' => 'mail',
                    'project_role_label' => 'Email',
                    'project_billing_anchor' => ! $bundled,
                ],
            ]);
        } else {
            $meta = is_array($emailService->service_meta) ? $emailService->service_meta : [];
            $meta['mailcow_domain'] = $primaryDomain;
            $meta['domain'] = $primaryDomain;
            $meta['additional_mail_domains'] = $extraDomains;
            $meta['migrated_from_service_id'] = $daService->id;
            $emailService->update([
                'product_id' => $product->id,
                'project_id' => $projectId > 0 ? $projectId : $emailService->project_id,
                'custom_price' => $bundled ? 0 : $emailService->custom_price,
                'service_meta' => $meta,
                'provisioning_driver_key' => 'mailcow',
            ]);
        }

        $this->mailcowProvisioning->provision($emailService->fresh(['product', 'node', 'user']));
        $emailService->refresh();

        $client = $this->mailcowProvisioning->clientForService($emailService);
        $limits = $this->mailcowProvisioning->limitsForProduct($product);
        $mailboxCount = array_sum(array_map('count', $byDomain));
        $domainMailboxCap = (string) max($limits['mailboxes'], $mailboxCount + 5);

        foreach ($extraDomains as $extraDomain) {
            $this->ensureMailcowDomain($client, $extraDomain, $emailService, $limits, $domainMailboxCap);
        }

        if ($extraDomains !== []) {
            $client->editDomain($primaryDomain, [
                'mailboxes' => $domainMailboxCap,
            ]);
        }

        $created = [];
        $syncJobs = [];
        $failed = [];
        $username = (string) ($daService->getHostingCredentials()['username']
            ?? $daService->service_meta['username']
            ?? $daService->external_reference
            ?? '');
        $api = $daService->node ? DirectAdminCustomerPanelApi::forServiceNode($daService->node) : null;

        foreach ($byDomain as $domain => $boxes) {
            foreach ($boxes as $box) {
                $local = strtolower(trim((string) ($box['account'] ?? '')));
                if (str_contains($local, '@')) {
                    $local = explode('@', $local, 2)[0];
                }
                if ($local === '') {
                    continue;
                }

                $email = $local.'@'.$domain;
                $mailcowPassword = $this->mailcowProvisioning->generateMailboxPassword();
                $add = $client->addMailbox([
                    'local_part' => $local,
                    'domain' => $domain,
                    'name' => $local,
                    'password' => $mailcowPassword,
                    'password2' => $mailcowPassword,
                    'quota' => (string) $limits['mailbox_quota_mb'],
                    'active' => '1',
                    'force_pw_update' => '1',
                ]);

                if (! ($add['success'] ?? false)) {
                    $failed[] = $email;
                    Log::info('Mailcow mailbox create skipped/failed during migrate', [
                        'mailbox' => $email,
                        'message' => $add['message'] ?? null,
                    ]);

                    continue;
                }

                $created[] = $email;

                if (! $pullMail || $imapHost === '') {
                    continue;
                }

                $imapPassword = $sharedImapPassword;
                if ($imapPassword === '' && $api && $username !== '') {
                    $imapPassword = $this->mailcowProvisioning->generateMailboxPassword();
                    $changed = $api->changeEmailAccountPassword($username, $domain, $local, $imapPassword);
                    if (! ($changed['success'] ?? false)) {
                        Log::warning('Could not set DirectAdmin IMAP password for mail pull', [
                            'mailbox' => $email,
                            'message' => $changed['message'] ?? null,
                        ]);
                        $failed[] = $email.' (DA password)';

                        continue;
                    }
                }

                if ($imapPassword === '') {
                    continue;
                }

                $sync = $client->addSyncJob([
                    'username' => $email,
                    'host1' => $imapHost,
                    'port1' => '993',
                    'user1' => $email,
                    'password1' => $imapPassword,
                    'enc1' => 'SSL',
                    'mins_interval' => '10',
                    'subfolder2' => '',
                    'maxage' => '0',
                    'exclude' => '',
                    'custom_params' => '',
                    'delete2duplicates' => '1',
                    'delete1' => '0',
                    'delete2' => '0',
                    'automap' => '1',
                    'skipcrossduplicates' => '0',
                    'active' => '1',
                ]);
                if ($sync['success'] ?? false) {
                    $syncJobs[] = $email;
                } else {
                    $failed[] = $email.' (sync)';
                    Log::warning('Mailcow sync job failed', [
                        'mailbox' => $email,
                        'message' => $sync['message'] ?? null,
                    ]);
                }
            }
        }

        $daMeta = is_array($daService->service_meta) ? $daService->service_meta : [];
        $daMeta['mailcow_migration'] = [
            'status' => 'migrated',
            'email_service_id' => $emailService->id,
            'migrated_at' => now()->toIso8601String(),
            'mailboxes_created' => $created,
            'sync_jobs' => $syncJobs,
            'failed_mailboxes' => $failed,
            'additional_mail_domains' => $extraDomains,
            'keep_email_on_da' => false,
            'note' => 'Mail is pulling into Mailcow. Cut over MX when sync has caught up, then decommission DirectAdmin.',
        ];
        $daService->update(['service_meta' => $daMeta]);

        if ($projectId > 0 && $emailService->project_id !== $projectId) {
            $emailService->update(['project_id' => $projectId]);
        }

        if ($projectId > 0) {
            $project = CustomerProject::query()->find($projectId);
            if ($project && ! $project->billing_service_id) {
                $project->update(['billing_service_id' => $daService->id]);
            }
        }

        return [
            'success' => true,
            'message' => 'Mail pulled to Mailcow. Created '.count($created).' mailbox(es)'
                .($syncJobs !== [] ? ', IMAP sync on '.count($syncJobs) : '')
                .($failed !== [] ? ', '.count($failed).' need review' : '')
                .'. Update MX, then DirectAdmin can be decommissioned.',
            'email_service' => $emailService->fresh(),
            'created_mailboxes' => $created,
            'sync_jobs' => $syncJobs,
            'failed_mailboxes' => $failed,
        ];
    }

    public function isUnownedDirectAdminDomain(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'you do not own that domain')
            || str_contains($message, 'you do not own this domain');
    }

    /**
     * @param  array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int, msgs_per_day: int}  $limits
     */
    private function ensureMailcowDomain(MailcowService $client, string $domain, Service $emailService, array $limits, string $mailboxCap): void
    {
        $existing = $client->getDomain($domain);
        $exists = ($existing['success'] ?? false) && ! empty($existing['data']);
        if ($exists) {
            $client->editDomain($domain, [
                'active' => '1',
                'mailboxes' => $mailboxCap,
            ]);

            return;
        }

        $created = $client->addDomain([
            'domain' => $domain,
            'description' => 'Talksasa service #'.$emailService->id.' extra domain',
            'aliases' => (string) $limits['aliases'],
            'mailboxes' => $mailboxCap,
            'defquota' => (string) $limits['mailbox_quota_mb'],
            'maxquota' => (string) $limits['mailbox_quota_mb'],
            'quota' => (string) $limits['quota_mb'],
            'active' => '1',
            'rl_value' => (string) $limits['msgs_per_day'],
            'rl_frame' => 'd',
            'restart_sogo' => '1',
        ]);

        if (! ($created['success'] ?? false)) {
            Log::warning('Mailcow extra domain create failed during DA mail pull', [
                'service_id' => $emailService->id,
                'domain' => $domain,
                'message' => $created['message'] ?? null,
            ]);
        }
    }
}
