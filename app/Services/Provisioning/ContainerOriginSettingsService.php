<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;

/**
 * The origins and hostnames a customer's application should be trusting: its
 * own domains, in the shape the setting expects.
 *
 * Two families that read alike and want opposite values. CORS origin lists
 * carry a scheme, so "https://example.com". Django's ALLOWED_HOSTS carries bare
 * hostnames, and writing an origin into it rejects every request the app
 * receives. Anything not in either list is left alone: guessing at a name
 * nobody here recognises is how a platform breaks an application quietly.
 *
 * Note for a split stack: the browser calls /api on the same origin through the
 * edge, so CORS is not involved in the site's own pages working. These values
 * matter to other clients, a mobile app above all, which is why filling them
 * with the site's own domains is both correct and low-stakes.
 */
class ContainerOriginSettingsService
{
    /**
     * Settings that hold origins, scheme included.
     *
     * @var list<string>
     */
    private const ORIGIN_KEYS = [
        'ALLOWED_ORIGINS',
        'CORS_ORIGINS',
        'CORS_ALLOWED_ORIGINS',
        'CORS_ORIGIN_WHITELIST',
        'BACKEND_CORS_ORIGINS',
        'CSRF_TRUSTED_ORIGINS',
    ];

    /**
     * Settings that hold bare hostnames. An origin here is a misconfiguration
     * that rejects every request, so the two lists must never be merged.
     *
     * @var list<string>
     */
    private const HOST_KEYS = [
        'ALLOWED_HOSTS',
        'DJANGO_ALLOWED_HOSTS',
    ];

    public function supports(string $key): bool
    {
        return $this->isOriginKey($key) || $this->isHostKey($key);
    }

    public function isOriginKey(string $key): bool
    {
        return in_array(strtoupper(trim($key)), self::ORIGIN_KEYS, true);
    }

    public function isHostKey(string $key): bool
    {
        return in_array(strtoupper(trim($key)), self::HOST_KEYS, true);
    }

    /**
     * What this setting should hold, as a JSON array, or null when the service
     * has no domain that would make the answer true.
     *
     * JSON because that is what pydantic and Django both read a list as. An
     * application that declares the same name as a plain string splits it on
     * commas itself and would take this whole array as one entry, which is why
     * nothing here writes a value the application has not already failed to
     * parse or left unset.
     */
    public function valueFor(ContainerDeployment $deployment, string $key): ?string
    {
        $values = $this->isHostKey($key)
            ? $this->hostnames($deployment)
            : $this->origins($deployment);

        if ($values === []) {
            return null;
        }

        return (string) json_encode($values, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The site's own origins. HTTPS only, and only from a domain that is live:
     * an origin the browser will never send is noise in a trust list.
     *
     * @return list<string>
     */
    public function origins(ContainerDeployment $deployment): array
    {
        $origins = [];

        foreach ($this->liveDomains($deployment) as $domain) {
            if (! $domain->ssl_enabled) {
                continue;
            }

            foreach ($this->hostVariants((string) $domain->domain) as $host) {
                $origins['https://'.$host] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * The site's own hostnames. SSL is irrelevant here: a hostname is a
     * hostname whether or not a certificate has been issued for it yet.
     *
     * @return list<string>
     */
    public function hostnames(ContainerDeployment $deployment): array
    {
        $hosts = [];

        foreach ($this->liveDomains($deployment) as $domain) {
            foreach ($this->hostVariants((string) $domain->domain) as $host) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * A sentence naming the value a customer should set, for a message that has
     * already told them the setting is wrong.
     *
     * @param  list<string>  $keys
     */
    public function suggestion(ContainerDeployment $deployment, array $keys): ?string
    {
        $parts = [];

        foreach ($keys as $key) {
            $key = trim((string) $key);
            if (! $this->supports($key)) {
                continue;
            }

            $value = $this->valueFor($deployment, $key);
            if ($value !== null) {
                $parts[] = $key.'='.$value;
            }
        }

        if ($parts === []) {
            return null;
        }

        return 'This service\'s own domains would make that '.implode(' and ', $parts).'.';
    }

    /**
     * Supply the settings an application declares and nobody has set.
     *
     * Only ever fills a blank. A value the customer chose is theirs, including
     * one that is about to fail, because a platform that silently rewrites what
     * somebody typed is worse to operate than one that reports the problem.
     *
     * @param  list<string>  $declaredKeys
     * @param  array<string, string>  $envVars
     * @return array<string, string> the keys that were filled
     */
    public function fillUnset(ContainerDeployment $deployment, array $declaredKeys, array &$envVars): array
    {
        $filled = [];

        foreach ($declaredKeys as $key) {
            $key = trim((string) $key);
            if (! $this->supports($key)) {
                continue;
            }

            if (trim((string) ($envVars[$key] ?? '')) !== '') {
                continue;
            }

            $value = $this->valueFor($deployment, $key);
            if ($value === null) {
                continue;
            }

            $envVars[$key] = $value;
            $filled[$key] = $value;
        }

        return $filled;
    }

    /**
     * @return list<ContainerDomain>
     */
    private function liveDomains(ContainerDeployment $deployment): array
    {
        $deployment->loadMissing('domains');

        return $deployment->domains
            ->filter(fn (ContainerDomain $domain): bool => $domain->status === 'active'
                && trim((string) $domain->domain) !== '')
            ->values()
            ->all();
    }

    /**
     * Both the apex and the www form, because a browser sends whichever the
     * visitor typed and a trust list that holds one of them fails on the other.
     *
     * @return list<string>
     */
    private function hostVariants(string $domain): array
    {
        $host = strtolower(trim($domain, " \t\n\r\0\x0B/"));
        if ($host === '') {
            return [];
        }

        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        // No attempt to tell an apex from a subdomain. Counting dots calls
        // chakula.co.ke a subdomain, and the same is true of every .co.uk,
        // .com.au and .ac.ke there is. The costs are not symmetric either: an
        // origin nobody sends is dead weight in a trust list, while a missing
        // www entry locks out half the visitors.
        return [$bare, 'www.'.$bare];
    }
}
