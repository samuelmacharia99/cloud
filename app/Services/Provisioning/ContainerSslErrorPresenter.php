<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;

class ContainerSslErrorPresenter
{
    /**
     * @return array{title: string, guidance: string, details: string}|null
     */
    public function present(ContainerDomain $domain, ?string $rawMessage = null): ?array
    {
        $details = trim($rawMessage ?? (string) $domain->error_message);
        if ($details === '') {
            return null;
        }

        $domain->loadMissing('deployment.node');

        if ($this->looksLikeSslFailure($details)) {
            return $this->explainSsl(
                $details,
                $domain->domain,
                $domain->deployment?->node?->ip_address
            );
        }

        return [
            'title' => "Couldn’t set up {$domain->domain}.",
            'guidance' => 'Review the details below, fix the reported problem, then try again.',
            'details' => $details,
        ];
    }

    public function flashMessage(array $presented): string
    {
        return trim($presented['title'].' '.$presented['guidance']);
    }

    /**
     * @return array{title: string, guidance: string, details: string}
     */
    public function explainSsl(string $details, string $hostname, ?string $expectedIp = null): array
    {
        $message = strtolower($details);
        $challenge = $this->extractChallenge($details);
        $pointTo = $expectedIp
            ? "Point the A record for {$hostname} to {$expectedIp}"
            : "Point the A record for {$hostname} to this app server";

        if ($this->contains($message, ['too many certificates', 'ratelimited', 'rate limit', 'retry-after'])) {
            return $this->result(
                "Let’s Encrypt rate limit reached for {$hostname}.",
                'Wait until the rate limit resets (usually a few hours), then retry SSL.',
                $details,
            );
        }

        if ($this->contains($message, ['caa record', 'caa checking'])) {
            return $this->result(
                "A CAA DNS record is blocking the certificate for {$hostname}.",
                "Allow letsencrypt.org in the CAA record for {$hostname}, then retry SSL.",
                $details,
            );
        }

        if ($this->contains($message, ['nxdomain', 'no valid ip addresses', 'dns problem', 'servfail', 'name does not exist'])) {
            return $this->result(
                "DNS for {$hostname} is not ready.",
                "{$pointTo}, wait a few minutes for DNS to update, then retry SSL.",
                $details,
            );
        }

        if ($this->contains($message, ['timeout during connect', 'connection refused', 'network is unreachable', 'connection timed out'])) {
            return $this->result(
                "Let’s Encrypt could not reach {$hostname}.",
                "{$pointTo} and make sure port 80 is open on the internet, then retry SSL.",
                $details,
            );
        }

        if ($this->contains($message, ['certbot: command not found', 'certbot is not installed'])) {
            return $this->result(
                'SSL issuance is not available on this server.',
                'Contact support so Let’s Encrypt can be installed on the app host.',
                $details,
            );
        }

        if ($this->contains($message, ['nginx is required', 'nginx is not installed'])) {
            return $this->result(
                'Nginx is not available on this server.',
                'Contact support. SSL certificates are issued through nginx on the app host.',
                $details,
            );
        }

        $challengeIp = $challenge['ip'];
        $httpStatus = $challenge['status'];
        $dnsMismatch = $challengeIp && $expectedIp && $challengeIp !== $expectedIp;

        if ($this->contains($message, ['unauthorized', 'acme-challenge', 'invalid response']) || $httpStatus !== null) {
            if ($dnsMismatch) {
                $statusHint = $httpStatus === '404'
                    ? "Let’s Encrypt reached {$challengeIp}, which returned 404 instead of this app server."
                    : "Let’s Encrypt reached {$challengeIp} instead of this app server ({$expectedIp}).";

                return $this->result(
                    "Let’s Encrypt could not verify {$hostname}.",
                    "{$statusHint} {$pointTo}, wait for DNS to update, then retry SSL.",
                    $details,
                );
            }

            if ($httpStatus === '404' && $challengeIp && $expectedIp && $challengeIp === $expectedIp) {
                return $this->result(
                    "Let’s Encrypt could not verify {$hostname}.",
                    'DNS points here, but the verification file returned 404. Confirm the domain is bound to this app and reachable over HTTP, then retry SSL.',
                    $details,
                );
            }

            return $this->result(
                "Let’s Encrypt could not verify {$hostname}.",
                "{$pointTo}, wait for DNS to update, and make sure the site is reachable over HTTP, then retry SSL.",
                $details,
            );
        }

        return $this->result(
            "SSL certificate could not be issued for {$hostname}.",
            "{$pointTo}, wait for DNS to update, then retry SSL.",
            $details,
        );
    }

    public function looksLikeSslFailure(string $details): bool
    {
        $message = strtolower($details);

        return $this->contains($message, [
            'certbot',
            'letsencrypt',
            "let's encrypt",
            'acme-challenge',
            'certificate',
            'ssl',
        ]);
    }

    /**
     * @return array{ip: ?string, host: ?string, status: ?string}
     */
    private function extractChallenge(string $details): array
    {
        $ip = null;
        $host = null;
        $status = null;

        if (preg_match('/Detail:\s*([0-9a-fA-F:.]+):\s*Invalid response from https?:\/\/([^\/\s:]+)/i', $details, $matches)) {
            $ip = $matches[1];
            $host = $matches[2];
        } elseif (preg_match('/Invalid response from https?:\/\/([^\/\s:]+)/i', $details, $matches)) {
            $host = $matches[1];
        }

        if (preg_match('/acme-challenge\/[^\s:]+:\s*(\d{3})/i', $details, $matches)) {
            $status = $matches[1];
        }

        return [
            'ip' => $ip,
            'host' => $host,
            'status' => $status,
        ];
    }

    /**
     * @return array{title: string, guidance: string, details: string}
     */
    private function result(string $title, string $guidance, string $details): array
    {
        return compact('title', 'guidance', 'details');
    }

    /**
     * @param  list<string>  $needles
     */
    private function contains(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
