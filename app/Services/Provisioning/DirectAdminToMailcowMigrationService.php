<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin-assisted migration of DirectAdmin mailboxes → Mailcow.
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
     *     mailboxes: list<array{account: string, email: string}>,
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

        $mailboxes = [];
        if ($daService->isSharedHosting() && $daService->node && $domain !== '') {
            try {
                $username = (string) ($daService->service_meta['username'] ?? $daService->external_reference ?? '');
                $api = DirectAdminCustomerPanelApi::forServiceNode($daService->node);
                $listed = $api->listEmailAccounts($username, $domain);
                if ($listed['success'] ?? false) {
                    foreach ($listed['data'] ?? [] as $row) {
                        $account = is_array($row) ? (string) ($row['account'] ?? $row['user'] ?? '') : (string) $row;
                        $account = trim($account);
                        if ($account === '') {
                            continue;
                        }
                        $local = str_contains($account, '@') ? explode('@', $account)[0] : $account;
                        $mailboxes[] = [
                            'account' => $local,
                            'email' => is_array($row) && ! empty($row['email'])
                                ? (string) $row['email']
                                : $local.'@'.$domain,
                        ];
                    }
                } else {
                    $blockers[] = 'Could not list DA mailboxes: '.($listed['message'] ?? 'unknown error');
                }
            } catch (\Throwable $e) {
                $blockers[] = 'Could not list DA mailboxes: '.$e->getMessage();
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
            'domain' => $domain !== '' ? $domain : null,
            'can_migrate' => $blockers === [],
            'blockers' => $blockers,
            'email_products' => $products->all(),
        ];
    }

    /**
     * Create (or reuse) email_hosting service, provision Mailcow domain, create mailboxes, optional sync jobs.
     *
     * @param  array{product_id: int, create_sync_jobs?: bool, da_imap_host?: string, da_imap_password?: string}  $options
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
        if ($product->type !== 'email_hosting') {
            return ['success' => false, 'message' => 'Selected product is not email hosting.'];
        }

        $domain = $preflight['domain'];
        $createSync = ! empty($options['create_sync_jobs']);
        $imapHost = (string) ($options['da_imap_host'] ?? $daService->node?->hostname ?? '');
        $imapPassword = (string) ($options['da_imap_password'] ?? '');

        return DB::transaction(function () use ($daService, $product, $domain, $preflight, $createSync, $imapHost, $imapPassword) {
            $emailService = Service::query()
                ->where('user_id', $daService->user_id)
                ->where('provisioning_driver_key', 'mailcow')
                ->where(function ($q) use ($domain) {
                    $q->where('external_reference', $domain)
                        ->orWhere('service_meta->mailcow_domain', $domain);
                })
                ->first();

            if (! $emailService) {
                $emailService = Service::create([
                    'user_id' => $daService->user_id,
                    'reseller_id' => $daService->reseller_id,
                    'product_id' => $product->id,
                    'name' => 'Email: '.$domain,
                    'status' => ServiceStatus::Pending,
                    'billing_cycle' => $daService->billing_cycle ?? 'monthly',
                    'next_due_date' => $daService->next_due_date,
                    'provisioning_driver_key' => 'mailcow',
                    'service_meta' => [
                        'mailcow_domain' => $domain,
                        'domain' => $domain,
                        'migrated_from_service_id' => $daService->id,
                    ],
                ]);
            } else {
                $meta = is_array($emailService->service_meta) ? $emailService->service_meta : [];
                $meta['mailcow_domain'] = $domain;
                $meta['domain'] = $domain;
                $meta['migrated_from_service_id'] = $daService->id;
                $emailService->update([
                    'product_id' => $product->id,
                    'service_meta' => $meta,
                    'provisioning_driver_key' => 'mailcow',
                ]);
            }

            $this->mailcowProvisioning->provision($emailService->fresh(['product', 'node', 'user']));
            $emailService->refresh();

            $client = $this->mailcowProvisioning->clientForService($emailService);
            $limits = $this->mailcowProvisioning->limitsForProduct($product);
            $created = [];
            $syncJobs = [];

            foreach ($preflight['mailboxes'] as $box) {
                $local = $box['account'];
                $password = $this->mailcowProvisioning->generateMailboxPassword();

                $add = $client->addMailbox([
                    'local_part' => $local,
                    'domain' => $domain,
                    'name' => $local,
                    'password' => $password,
                    'password2' => $password,
                    'quota' => (string) $limits['mailbox_quota_mb'],
                    'active' => '1',
                    'force_pw_update' => '1',
                ]);

                if ($add['success']) {
                    $created[] = $local.'@'.$domain;

                    if ($createSync && $imapHost !== '' && $imapPassword !== '') {
                        $sync = $client->addSyncJob([
                            'username' => $local.'@'.$domain,
                            'host1' => $imapHost,
                            'port1' => '993',
                            'user1' => $local.'@'.$domain,
                            'password1' => $imapPassword,
                            'enc1' => 'SSL',
                            'mins_interval' => '20',
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
                        if ($sync['success']) {
                            $syncJobs[] = $local.'@'.$domain;
                        } else {
                            Log::warning('Mailcow sync job failed', [
                                'mailbox' => $local.'@'.$domain,
                                'message' => $sync['message'] ?? null,
                            ]);
                        }
                    }
                } else {
                    // Mailbox may already exist
                    Log::info('Mailcow mailbox create skipped/failed during migrate', [
                        'mailbox' => $local.'@'.$domain,
                        'message' => $add['message'] ?? null,
                    ]);
                }
            }

            $daMeta = is_array($daService->service_meta) ? $daService->service_meta : [];
            $daMeta['mailcow_migration'] = [
                'status' => 'migrated',
                'email_service_id' => $emailService->id,
                'migrated_at' => now()->toIso8601String(),
                'mailboxes_created' => $created,
                'sync_jobs' => $syncJobs,
                'note' => 'Cut over MX to Mailcow when IMAP sync is caught up, then disable DA mail.',
            ];
            $daService->update(['service_meta' => $daMeta]);

            return [
                'success' => true,
                'message' => 'Mail domain provisioned on Mailcow. Created '.count($created).' mailbox(es).'
                    .($syncJobs !== [] ? ' Sync jobs: '.count($syncJobs).'.' : ' Set passwords / sync manually if needed.'),
                'email_service' => $emailService,
                'created_mailboxes' => $created,
                'sync_jobs' => $syncJobs,
            ];
        });
    }
}
