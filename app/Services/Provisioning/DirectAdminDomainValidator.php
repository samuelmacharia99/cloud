<?php

namespace App\Services\Provisioning;

use InvalidArgumentException;

class DirectAdminDomainValidator
{
    public function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/.');

        return $domain;
    }

    public function isValid(string $domain): bool
    {
        $domain = $this->normalize($domain);

        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if ($this->isPlaceholder($domain)) {
            return false;
        }

        return (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain);
    }

    public function assertValid(string $domain): string
    {
        $domain = $this->normalize($domain);

        if (! $this->isValid($domain)) {
            throw new InvalidArgumentException(
                'A valid primary domain is required for shared hosting (e.g. example.com). Placeholder domains like .local are not allowed.'
            );
        }

        return $domain;
    }

    public function isPlaceholder(string $domain): bool
    {
        $domain = $this->normalize($domain);

        return str_ends_with($domain, '.local')
            || str_ends_with($domain, '.localhost')
            || str_ends_with($domain, '.test')
            || str_ends_with($domain, '.invalid')
            || $domain === 'localhost';
    }

    public function splitFqdn(string $fqdn): array
    {
        $fqdn = $this->assertValid($fqdn);
        $parts = explode('.', $fqdn, 2);

        if (count($parts) < 2) {
            throw new InvalidArgumentException('Domain must include a TLD (e.g. example.com).');
        }

        return [
            'name' => $parts[0],
            'extension' => '.'.$parts[1],
            'fqdn' => $fqdn,
        ];
    }
}
