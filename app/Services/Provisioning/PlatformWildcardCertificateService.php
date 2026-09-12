<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;

/**
 * One wildcard certificate per container host for the platform apps zone.
 *
 * Every stack gets a hostname under that zone, and Let's Encrypt allows fifty
 * new certificates per registered domain per week. Issuing one per hostname
 * would exhaust that the first afternoon the rollout runs. A wildcard proves
 * ownership over DNS instead, so it is issued once per node with the
 * Cloudflare plugin and every platform vhost on the node points at it.
 *
 * The DNS token written to the node is a separate setting from the broad
 * Cloudflare token the panel uses, so a compromised host holds a credential
 * scoped to editing records in one zone, nothing wider.
 */
class PlatformWildcardCertificateService
{
    public const CREDENTIALS_PATH = '/etc/letsencrypt/talksasa-apps-cloudflare.ini';

    /** Renew when fewer than this many seconds remain: thirty days. */
    private const RENEW_WITHIN_SECONDS = 2592000;

    private const ISSUE_TIMEOUT = 420;

    /**
     * @return array{cert: string, key: string}
     */
    public function certificatePaths(string $zone): array
    {
        $zone = $this->assertSafeZone($zone);

        return [
            'cert' => "/etc/letsencrypt/live/{$zone}/fullchain.pem",
            'key' => "/etc/letsencrypt/live/{$zone}/privkey.pem",
        ];
    }

    /**
     * Issue the wildcard on this node unless a valid one is already there.
     *
     * @return array{cert: string, key: string}
     */
    public function ensureOnNode(SSHService $ssh, Node $node, string $zone, string $dnsToken, string $email): array
    {
        $zone = $this->assertSafeZone($zone);
        $paths = $this->certificatePaths($zone);

        if ($this->isValidOnNode($ssh, $paths['cert'])) {
            return $paths;
        }

        // Two deploys landing on a fresh node at once must not both call
        // certbot; the second waits and finds the first one's certificate.
        $lock = Cache::lock('platform-wildcard-cert:node:'.$node->id, 600);
        $lock->block(300);

        try {
            if ($this->isValidOnNode($ssh, $paths['cert'])) {
                return $paths;
            }

            $this->ensurePlugin($ssh);
            $this->writeCredentials($ssh, $dnsToken);

            $output = trim((string) $ssh->exec(
                'certbot certonly --non-interactive --agree-tos'
                .' --email '.escapeshellarg($email)
                .' --dns-cloudflare --dns-cloudflare-credentials '.escapeshellarg(self::CREDENTIALS_PATH)
                .' --dns-cloudflare-propagation-seconds 30'
                .' --cert-name '.escapeshellarg($zone)
                .' -d '.escapeshellarg($zone).' -d '.escapeshellarg('*.'.$zone)
                .' 2>&1',
                self::ISSUE_TIMEOUT
            ));

            if (! $this->isValidOnNode($ssh, $paths['cert'])) {
                throw new \RuntimeException(
                    "Wildcard certificate for {$zone} was not issued on {$node->hostname}: "
                    .mb_substr($output, -600)
                );
            }

            return $paths;
        } finally {
            $lock->release();
        }
    }

    /**
     * Renew the node's wildcard if it is due. Certbot itself decides whether
     * the lineage is close enough to expiry to bother.
     */
    public function renewOnNode(SSHService $ssh, Node $node, string $zone, NginxProxyService $nginx): void
    {
        $zone = $this->assertSafeZone($zone);

        $output = trim((string) $ssh->exec(
            'certbot renew --cert-name '.escapeshellarg($zone).' --non-interactive 2>&1',
            self::ISSUE_TIMEOUT
        ));

        if (preg_match('/error|failed/i', $output) === 1 && ! $this->isValidOnNode($ssh, $this->certificatePaths($zone)['cert'])) {
            throw new \RuntimeException("Wildcard renewal for {$zone} failed on {$node->hostname}: ".mb_substr($output, -600));
        }

        $nginx->reload($ssh, $node);
    }

    public function isValidOnNode(SSHService $ssh, string $certPath): bool
    {
        try {
            $answer = trim((string) $ssh->exec(
                '[ -f '.escapeshellarg($certPath).' ] && openssl x509 -checkend '.self::RENEW_WITHIN_SECONDS
                .' -noout -in '.escapeshellarg($certPath).' >/dev/null 2>&1 && echo valid || echo missing',
                20
            ));
        } catch (\Throwable) {
            return false;
        }

        return $answer === 'valid';
    }

    private function ensurePlugin(SSHService $ssh): void
    {
        $ssh->exec(
            'python3 -c "import certbot_dns_cloudflare" >/dev/null 2>&1'
            .' || (DEBIAN_FRONTEND=noninteractive apt-get update -qq'
            .' && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends certbot python3-certbot-dns-cloudflare)',
            self::ISSUE_TIMEOUT
        );
    }

    private function writeCredentials(SSHService $ssh, string $dnsToken): void
    {
        $token = trim($dnsToken);
        if ($token === '' || ! preg_match('/^[A-Za-z0-9\-_]+$/', $token)) {
            throw new \RuntimeException('The platform apps DNS token is empty or malformed.');
        }

        $ssh->exec('mkdir -p '.escapeshellarg(dirname(self::CREDENTIALS_PATH)), 15);
        $ssh->upload("dns_cloudflare_api_token = {$token}\n", self::CREDENTIALS_PATH);
        $ssh->exec('chmod 600 '.escapeshellarg(self::CREDENTIALS_PATH), 15);
    }

    private function assertSafeZone(string $zone): string
    {
        $zone = strtolower(trim($zone, " \t\n\r."));
        if ($zone === '' || ! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $zone)) {
            throw new \InvalidArgumentException("Platform apps zone is not a valid hostname: {$zone}");
        }

        return $zone;
    }
}
