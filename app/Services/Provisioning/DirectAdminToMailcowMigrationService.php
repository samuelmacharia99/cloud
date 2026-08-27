<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                'ssh_scanned' => false,
                'virtual_passwd_scanned' => false,
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

        $sshScanned = false;
        $virtualPasswdScanned = false;
        if ($all === [] && $daService->node) {
            $sshListed = $this->listMailboxesViaSsh($daService->node, $username, $domains);
            $sshScanned = true;
            $virtualPasswdScanned = (bool) ($sshListed['virtual_passwd_scanned'] ?? false);
            foreach ($sshListed['errors'] as $error) {
                $errors[] = $error;
            }
            if ($sshListed['all'] !== []) {
                $byDomain = $sshListed['by_domain'];
                $all = $sshListed['all'];
            }
        }

        return [
            'by_domain' => $byDomain,
            'all' => $all,
            'errors' => $errors,
            'ssh_scanned' => $sshScanned,
            'virtual_passwd_scanned' => $virtualPasswdScanned,
        ];
    }

    /**
     * Fallback when CMD_API_POP returns empty: maildirs plus /etc/virtual/{domain}/passwd.
     *
     * @param  list<string>  $domains
     * @return array{
     *     by_domain: array<string, list<array{account: string, email: string, domain: string}>>,
     *     all: list<array{account: string, email: string, domain: string}>,
     *     errors: list<string>,
     *     virtual_passwd_scanned: bool
     * }
     */
    public function listMailboxesViaSsh(Node $node, string $username, array $domains = []): array
    {
        $username = trim($username);
        $byDomain = [];
        $all = [];
        $errors = [];
        $virtualPasswdScanned = false;

        if ($username === '') {
            return [
                'by_domain' => [],
                'all' => [],
                'errors' => ['Missing DirectAdmin username for SSH mailbox scan.'],
                'virtual_passwd_scanned' => false,
            ];
        }

        $home = '/home/'.trim($username, '/');
        $domainsFilter = array_values(array_unique(array_filter(array_map(
            fn ($domain) => strtolower(trim((string) $domain)),
            $domains,
        ))));
        $raw = '';

        try {
            $ssh = SSHService::forNode($node);
            try {
                $command = '{ for base in '.escapeshellarg($home.'/imap').' '.escapeshellarg($home.'/Maildir').'; do '
                    .'if [ -d "$base" ]; then '
                    .'find "$base" -mindepth 2 -maxdepth 2 -type d 2>/dev/null; '
                    .'fi; '
                    .'done; } || true';
                $raw = trim((string) $ssh->exec($command));

                if ($domainsFilter !== []) {
                    try {
                        $virtRaw = trim((string) $ssh->exec($this->virtualPasswdListCommand($domainsFilter)));
                        $virtualPasswdScanned = true;
                        foreach ($this->parseVirtualPasswdNameLines($virtRaw) as $item) {
                            $this->appendMailbox($byDomain, $all, $item);
                        }
                    } catch (\Throwable) {
                        $virtualPasswdScanned = false;
                    }
                }
            } finally {
                $ssh->disconnect();
            }
        } catch (\Throwable $e) {
            return [
                'by_domain' => [],
                'all' => [],
                'errors' => ['SSH mailbox scan: '.$e->getMessage()],
                'virtual_passwd_scanned' => $virtualPasswdScanned,
            ];
        }

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, $home.'/')) {
                continue;
            }
            $relative = substr($line, strlen($home) + 1);
            $parts = explode('/', $relative);
            if (count($parts) < 3) {
                continue;
            }
            $domain = strtolower(trim($parts[1] ?? ''));
            $account = trim($parts[2] ?? '');
            if ($domain === '' || $account === '' || ! str_contains($domain, '.')) {
                continue;
            }
            if ($domainsFilter !== [] && ! in_array($domain, $domainsFilter, true)) {
                continue;
            }
            $this->appendMailbox($byDomain, $all, [
                'account' => $account,
                'email' => strtolower($account.'@'.$domain),
                'domain' => $domain,
            ]);
        }

        if ($all === [] && $domainsFilter !== []) {
            $errors[] = 'SSH mailbox scan found no maildirs under '.$home.'/imap or '.$home.'/Maildir'
                .($virtualPasswdScanned ? ', and /etc/virtual/{domain}/passwd listed none.' : '.');
        }

        return [
            'by_domain' => $byDomain,
            'all' => $all,
            'errors' => $errors,
            'virtual_passwd_scanned' => $virtualPasswdScanned,
        ];
    }

    /**
     * @param  list<string>  $domains
     */
    public function virtualPasswdListCommand(array $domains): string
    {
        $parts = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '' || ! str_contains($domain, '.')) {
                continue;
            }
            $quoted = escapeshellarg('/etc/virtual/'.$domain.'/passwd');
            $dom = escapeshellarg($domain);
            $parts[] = '{ cat '.$quoted.' || sudo -n cat '.$quoted.'; } 2>/dev/null'
                .' | awk -F: -v d='.$dom.' \'$1 != "" && $1 !~ /^#/ { print d ":" $1 }\'';
        }

        if ($parts === []) {
            return 'true';
        }

        return '{ '.implode('; ', $parts).'; } || true';
    }

    /**
     * @return list<array{account: string, email: string, domain: string}>
     */
    public function parseVirtualPasswdNameLines(string $raw): array
    {
        $items = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$domain, $local] = explode(':', $line, 2);
            $parsed = $this->parseVirtualPasswdLines($local."\n", $domain);
            foreach ($parsed as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array{account: string, email: string, domain: string}>
     */
    public function parseVirtualPasswdLines(string $raw, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $items = [];
        if ($domain === '' || ! str_contains($domain, '.')) {
            return [];
        }

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $local = strtolower(trim((string) explode(':', $line, 2)[0]));
            if ($local === '' || ! preg_match('/^[a-z0-9._+-]+$/', $local)) {
                continue;
            }
            $items[] = [
                'account' => $local,
                'email' => $local.'@'.$domain,
                'domain' => $domain,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, list<array{account: string, email: string, domain: string}>>  $byDomain
     * @param  list<array{account: string, email: string, domain: string}>  $all
     * @param  array{account: string, email: string, domain: string}  $item
     */
    private function appendMailbox(array &$byDomain, array &$all, array $item): void
    {
        $domain = strtolower((string) ($item['domain'] ?? ''));
        $account = strtolower(trim((string) ($item['account'] ?? '')));
        if ($domain === '' || $account === '') {
            return;
        }
        $item['account'] = $account;
        $item['domain'] = $domain;
        $item['email'] = strtolower((string) ($item['email'] ?? ($account.'@'.$domain)));
        $byDomain[$domain] ??= [];
        $existing = array_column($byDomain[$domain], 'account');
        if (in_array($account, $existing, true)) {
            return;
        }
        $byDomain[$domain][] = $item;
        $all[] = $item;
    }

    /**
     * Re-copy maildirs and recreate IMAP sync jobs after convert when Mailcow inboxes are empty.
     *
     * @return array{success: bool, message: string, copied_maildirs?: list<string>, sync_jobs?: list<string>, failed_mailboxes?: list<string>}
     */
    public function retryMailContentPull(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $legacy = is_array($meta['da_legacy'] ?? null) ? $meta['da_legacy'] : [];
        $migration = is_array($meta['mailcow_migration'] ?? null) ? $meta['mailcow_migration'] : [];

        $emailId = (int) ($legacy['email_service_id'] ?? $migration['email_service_id'] ?? 0);
        $emailService = $emailId > 0 ? Service::query()->find($emailId) : null;
        if (! $emailService) {
            return ['success' => false, 'message' => 'No Mailcow email service is linked to this convert.'];
        }

        $product = $emailService->product;
        if (! $product || $product->type !== 'email_hosting') {
            return ['success' => false, 'message' => 'Linked Email Hosting product is missing.'];
        }

        $byDomain = $this->mailboxMapFromEmails($migration['mailboxes_created'] ?? []);

        $daNode = Node::query()->find((int) ($legacy['da_node_id'] ?? 0));
        $username = (string) ($legacy['username'] ?? '');
        $domain = strtolower(trim((string) ($legacy['domain'] ?? $meta['domain'] ?? '')));
        if ($byDomain === [] && $daNode && $username !== '') {
            $listed = $this->listMailboxesViaSsh($daNode, $username, $domain !== '' ? [$domain] : []);
            $byDomain = $listed['by_domain'];
        }

        if ($byDomain === []) {
            return ['success' => false, 'message' => 'No DirectAdmin mailboxes to copy. Check /etc/virtual/{domain}/passwd on the DA node.'];
        }

        return $this->pullFromDirectAdminUser($service, $product, $byDomain, [
            'pull_mail' => true,
            'da_imap_host' => (string) ($daNode?->hostname ?: $daNode?->ip_address ?: ''),
            'bundled' => (bool) (($emailService->service_meta['email_domain_mode'] ?? '') === 'bundled_with_container'),
            'project_id' => $service->project_id,
        ]);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, list<array{account: string, email: string, domain: string}>>
     */
    public function mailboxMapFromEmails(array $emails): array
    {
        $byDomain = [];
        $all = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }
            [$local, $domain] = explode('@', $email, 2);
            $this->appendMailbox($byDomain, $all, [
                'account' => $local,
                'email' => $email,
                'domain' => $domain,
            ]);
        }

        return $byDomain;
    }

    public function mailcowMailboxAlreadyExists(array $add): bool
    {
        $message = strtolower((string) ($add['message'] ?? json_encode($add['data'] ?? [])));

        return str_contains($message, 'exist')
            || str_contains($message, 'object_exists');
    }

    public function mailcowMailboxExists(MailcowService $client, string $domain, string $email): bool
    {
        $listed = $client->listMailboxes($domain);
        if (! ($listed['success'] ?? false)) {
            return false;
        }

        $email = strtolower($email);
        foreach ($listed['data'] ?? [] as $row) {
            if (! is_array($row)) {
                if (strtolower((string) $row) === $email) {
                    return true;
                }

                continue;
            }
            $candidate = strtolower((string) ($row['username'] ?? ''));
            if ($candidate === '' && isset($row['local_part'], $row['domain'])) {
                $candidate = strtolower($row['local_part'].'@'.$row['domain']);
            }
            if ($candidate === $email) {
                return true;
            }
        }

        return false;
    }

    public function setDirectAdminMailboxPassword(
        ?DirectAdminCustomerPanelApi $api,
        Node $daNode,
        string $username,
        string $domain,
        string $local,
        string $password,
    ): bool {
        if ($api && $username !== '') {
            try {
                $changed = $api->changeEmailAccountPassword($username, $domain, $local, $password);
                if ($changed['success'] ?? false) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return $this->changeDirectAdminEmailPasswordViaSsh($daNode, $domain, $local, $password);
    }

    public function updateVirtualPasswdHashCommand(string $domain, string $localPart, string $password): string
    {
        $domain = strtolower(preg_replace('/[^a-z0-9.-]/', '', $domain) ?: '');
        $user = strtolower(preg_replace('/[^a-z0-9._+-]/', '', $localPart) ?: '');
        $file = '/etc/virtual/'.$domain.'/passwd';
        $passB64 = base64_encode($password);

        return 'set +o pipefail; '
            .'FILE='.escapeshellarg($file).'; USER='.escapeshellarg($user).'; '
            .'PASS=$(printf %s '.escapeshellarg($passB64).' | base64 -d); '
            .'if [ ! -f "$FILE" ]; then echo missing; exit 1; fi; '
            .'if ! grep -q "^${USER}:" "$FILE"; then echo nouser; exit 1; fi; '
            .'HASH=$(printf %s "$PASS" | openssl passwd -6 -stdin 2>/dev/null || printf %s "$PASS" | python3 -c \'import crypt,sys; print(crypt.crypt(sys.stdin.read().rstrip("\\n"), crypt.METHOD_SHA512))\'); '
            .'if [ -z "$HASH" ]; then echo nohash; exit 1; fi; '
            .'awk -F: -v u="$USER" -v h="$HASH" \'BEGIN{OFS=FS} $1==u{$2=h} {print}\' "$FILE" > "$FILE.talksasa" '
            .'&& mv "$FILE.talksasa" "$FILE" && chmod 640 "$FILE" && echo ok || echo fail';
    }

    public function changeDirectAdminEmailPasswordViaSsh(Node $daNode, string $domain, string $local, string $password): bool
    {
        try {
            $ssh = SSHService::forNode($daNode);
            $out = trim((string) $ssh->exec($this->updateVirtualPasswdHashCommand($domain, $local, $password), 30));
            $ssh->disconnect();
        } catch (\Throwable $e) {
            Log::warning('SSH DirectAdmin mailbox password update failed', [
                'domain' => $domain,
                'local' => $local,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return str_contains($out, 'ok');
    }

    public function locateDirectAdminMaildirCommand(string $username, string $domain, string $local): string
    {
        $home = '/home/'.trim($username, '/');
        $domain = strtolower($domain);
        $local = strtolower($local);

        return 'set +o pipefail; '
            .'for p in '
            .escapeshellarg($home.'/imap/'.$domain.'/'.$local).' '
            .escapeshellarg($home.'/imap/'.$domain.'/'.$local.'/Maildir').' '
            .escapeshellarg($home.'/Maildir').'; do '
            .'if [ -d "$p/cur" ]; then printf "%s\n" "$p"; exit 0; fi; '
            .'done; echo none';
    }

    public function extractMaildirIntoMailcowCommand(string $email, string $remoteTar): string
    {
        $email = strtolower($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dest = '/var/vmail/'.$domain.'/'.$local;

        return 'set +o pipefail; '
            .'MC=/opt/mailcow-dockerized; [ -d "$MC" ] || MC=/opt/mailcow; '
            .'cd "$MC" || exit 1; '
            .'docker compose exec -T dovecot-mailcow mkdir -p '.escapeshellarg($dest).' || exit 1; '
            .'docker compose exec -T dovecot-mailcow tar -xzf - -C '.escapeshellarg($dest)
            .' < '.escapeshellarg($remoteTar).' || exit 1; '
            .'docker compose exec -T dovecot-mailcow chown -R vmail:vmail '.escapeshellarg($dest).' || true; '
            .'docker compose exec -T dovecot-mailcow doveadm force-resync -u '.escapeshellarg($email).' "*" || true; '
            .'docker compose exec -T dovecot-mailcow doveadm quota recalc -u '.escapeshellarg($email).' || true; '
            .'echo copied';
    }

    public function copyDirectAdminMaildirToMailcow(
        Node $daNode,
        Node $mailcowNode,
        string $username,
        string $domain,
        string $local,
        string $email,
    ): bool {
        $workId = 'mail-'.$local.'-'.Str::lower(Str::random(6));
        $remoteTar = '/tmp/'.$workId.'.tgz';
        $localTar = storage_path('app/migrations/'.$workId.'.tgz');
        if (! is_dir(dirname($localTar))) {
            mkdir(dirname($localTar), 0755, true);
        }

        $daSsh = null;
        $mailSsh = null;

        try {
            $daSsh = SSHService::forNode($daNode);
            $mailSsh = SSHService::forNode($mailcowNode);
            $maildir = trim((string) $daSsh->exec(
                $this->locateDirectAdminMaildirCommand($username, $domain, $local),
                20
            ));
            if ($maildir === '' || $maildir === 'none' || ! str_starts_with($maildir, '/')) {
                return false;
            }

            $daSsh->exec(
                'tar -czf '.escapeshellarg($remoteTar).' -C '.escapeshellarg($maildir).' .',
                600
            );
            $daSsh->downloadToLocal($remoteTar, $localTar);
            @$daSsh->exec('rm -f '.escapeshellarg($remoteTar));

            $mailSsh->uploadFromLocal($localTar, $remoteTar);
            $out = trim((string) $mailSsh->exec(
                $this->extractMaildirIntoMailcowCommand($email, $remoteTar),
                600
            ));
            @$mailSsh->exec('rm -f '.escapeshellarg($remoteTar));

            return str_contains($out, 'copied');
        } catch (\Throwable $e) {
            Log::warning('DirectAdmin maildir copy to Mailcow failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $daSsh?->disconnect();
            $mailSsh?->disconnect();
            if (is_file($localTar)) {
                @unlink($localTar);
            }
        }
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
        $copied = [];
        $failed = [];
        $legacy = is_array($daService->service_meta['da_legacy'] ?? null) ? $daService->service_meta['da_legacy'] : [];
        $username = (string) ($legacy['username']
            ?? ($daService->getHostingCredentials()['username'] ?? null)
            ?? $daService->service_meta['username']
            ?? $daService->external_reference
            ?? '');
        $daNode = $daService->node;
        $legacyNodeId = (int) ($legacy['da_node_id'] ?? 0);
        if ($legacyNodeId > 0) {
            $fromLegacy = Node::query()->find($legacyNodeId);
            if ($fromLegacy) {
                $daNode = $fromLegacy;
            }
        }
        $api = $daNode ? DirectAdminCustomerPanelApi::forServiceNode($daNode) : null;
        $mailcowNode = $this->mailcowProvisioning->resolveNode($emailService);
        if ($imapHost === '' && $daNode) {
            $imapHost = (string) ($daNode->hostname ?: $daNode->ip_address);
        }

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

                $mailboxReady = ($add['success'] ?? false)
                    || $this->mailcowMailboxAlreadyExists($add)
                    || $this->mailcowMailboxExists($client, $domain, $email);

                if (! $mailboxReady) {
                    $failed[] = $email;
                    Log::info('Mailcow mailbox create skipped/failed during migrate', [
                        'mailbox' => $email,
                        'message' => $add['message'] ?? null,
                    ]);

                    continue;
                }

                if ($add['success'] ?? false) {
                    $created[] = $email;
                }

                if (! $pullMail) {
                    continue;
                }

                if ($daNode && $mailcowNode && $username !== '') {
                    if ($this->copyDirectAdminMaildirToMailcow($daNode, $mailcowNode, $username, $domain, $local, $email)) {
                        $copied[] = $email;
                    } else {
                        $failed[] = $email.' (maildir copy)';
                    }
                }

                if ($imapHost === '') {
                    continue;
                }

                $imapPassword = $sharedImapPassword;
                if ($imapPassword === '' && $username !== '' && $daNode) {
                    $imapPassword = $this->mailcowProvisioning->generateMailboxPassword();
                    $changed = $this->setDirectAdminMailboxPassword(
                        $api,
                        $daNode,
                        $username,
                        $domain,
                        $local,
                        $imapPassword,
                    );
                    if (! $changed) {
                        Log::warning('Could not set DirectAdmin IMAP password for mail pull', [
                            'mailbox' => $email,
                        ]);
                        $failed[] = $email.' (DA password)';
                        $imapPassword = '';
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
            'copied_maildirs' => $copied,
            'failed_mailboxes' => $failed,
            'additional_mail_domains' => $extraDomains,
            'keep_email_on_da' => false,
            'note' => ($copied !== [] || $syncJobs !== [])
                ? 'Mail is on Mailcow. Confirm inboxes, then cut over MX and decommission DirectAdmin.'
                : 'Mailboxes exist on Mailcow but content may still be on DirectAdmin. Retry mail pull (SSH maildir copy).',
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
                .($copied !== [] ? ', copied maildir for '.count($copied) : '')
                .($syncJobs !== [] ? ', IMAP sync on '.count($syncJobs) : '')
                .($failed !== [] ? ', '.count($failed).' need review' : '')
                .'. Update MX, then DirectAdmin can be decommissioned.',
            'email_service' => $emailService->fresh(),
            'created_mailboxes' => $created,
            'copied_maildirs' => $copied,
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
