<?php

namespace App\Services\Registrar\Drivers;

use App\Models\Domain;
use App\Models\Registrar;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\RegistrarOperationsInterface;
use Illuminate\Support\Carbon;

class CosmotownRegistrarDriver implements RegistrarOperationsInterface
{
    public function testConnection(Registrar $registrar): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);
            $ping = $client->ping();
            $listed = $client->listDomains(5, 0);
            $sampleCount = count($listed['domains']);
            $host = parse_url($client->baseUrl(), PHP_URL_HOST) ?: 'cosmotown.com';
            $environment = $registrar->environment === 'sandbox' ? 'sandbox' : 'production';

            return [
                'success' => true,
                'message' => "Connected to Cosmotown {$environment} ({$host}) from IP {$ping['ip']}. Account lists {$sampleCount} domain(s) in this sample.",
                'ip' => $ping['ip'],
                'domain_sample_count' => $sampleCount,
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'http_status' => $e->httpStatus,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function supportsRegistration(): bool
    {
        return true;
    }

    public function supportsTransfer(): bool
    {
        return true;
    }

    public function supportsRenewal(): bool
    {
        return true;
    }

    public function checkAvailability(Registrar $registrar, string $name, string $extension, bool $withPrice = false): array
    {
        return [
            'available' => false,
            'status' => 'unsupported',
            'price' => null,
            'premium' => null,
            'is_premium' => false,
            'source' => 'cosmotown',
            'message' => 'Cosmotown Reseller API does not expose availability checks; the platform uses WHOIS/DNS.',
        ];
    }

    public function registerDomain(Registrar $registrar, Domain $domain, int $years, array $nameServers): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);
            $fqdn = $this->fqdn($domain);
            $couponId = trim((string) (($registrar->config ?? [])['coupon_id'] ?? ''));

            $this->ensureDefaultContacts($registrar, $client);

            $rows = $client->registerDomains([
                ['name' => $fqdn, 'years' => max(1, $years)],
            ], $couponId);
            $row = $this->rowForDomain($rows, $fqdn);
            $mapped = $this->mapLifecycleRow($row, 'Domain registration submitted to Cosmotown.');

            if ($mapped['success']) {
                $this->pushNameserversQuietly($client, $fqdn, $nameServers);
                $mapped = $this->enrichFromDomainInfo($client, $fqdn, $mapped);
            }

            $mapped['external_id'] = $fqdn;

            return $mapped;
        } catch (CosmotownException $e) {
            return $this->failedLifecycle($e->getMessage());
        } catch (\Throwable $e) {
            return $this->failedLifecycle($e->getMessage());
        }
    }

    public function transferDomain(Registrar $registrar, Domain $domain, string $authCode, array $nameServers): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);
            $fqdn = $this->fqdn($domain);
            $code = trim($authCode);

            if ($code === '') {
                return $this->failedLifecycle('EPP / auth code is required for transfer.');
            }

            $this->ensureDefaultContacts($registrar, $client);

            $rows = $client->transferDomains([
                [
                    'name' => $fqdn,
                    'authCode' => base64_encode($code),
                ],
            ]);
            $row = $this->rowForDomain($rows, $fqdn);
            $mapped = $this->mapLifecycleRow($row, 'Domain transfer submitted to Cosmotown.');

            if ($mapped['success']) {
                $this->pushNameserversQuietly($client, $fqdn, $nameServers);
            }

            $mapped['external_id'] = $fqdn;

            return $mapped;
        } catch (CosmotownException $e) {
            return $this->failedLifecycle($e->getMessage());
        } catch (\Throwable $e) {
            return $this->failedLifecycle($e->getMessage());
        }
    }

    public function renewDomain(Registrar $registrar, Domain $domain, int $years): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);
            $fqdn = $this->fqdn($domain);
            $response = $client->renewDomains([
                ['name' => $fqdn, 'years' => max(1, $years)],
            ]);

            $statusText = strtolower((string) ($response['status'] ?? $response['message'] ?? ''));
            $accepted = $statusText === '' || str_contains($statusText, 'processed') || str_contains($statusText, 'success');

            if (! $accepted) {
                return [
                    'success' => false,
                    'status' => 'FAI',
                    'expiration_date' => null,
                    'message' => $this->rowMessage($response, 'Cosmotown rejected the renewal.'),
                ];
            }

            $info = [];
            try {
                $info = $client->getDomainInfo($fqdn);
            } catch (CosmotownException) {
                $info = [];
            }

            return [
                'success' => true,
                'status' => 'ACT',
                'expiration_date' => $this->expirationFromInfo($info),
                'message' => 'Domain renewal submitted to Cosmotown.',
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'status' => 'FAI',
                'expiration_date' => null,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'FAI',
                'expiration_date' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function syncDomainStatus(Registrar $registrar, Domain $domain): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);
            $fqdn = $this->fqdn($domain);

            $statusRows = $client->getDomainStatus([$fqdn]);
            $statusRow = $this->rowForDomain($statusRows, $fqdn);
            $registrationStatus = strtolower((string) (
                $statusRow['registration_status']
                ?? $statusRow['status']
                ?? ''
            ));

            $info = [];
            try {
                $info = $client->getDomainInfo($fqdn);
            } catch (CosmotownException) {
                $info = [];
            }

            $mappedStatus = $this->mapCosmotownStatus($registrationStatus !== '' ? $registrationStatus : $this->statusFromInfo($info));

            return [
                'success' => true,
                'status' => $mappedStatus,
                'expiration_date' => $this->expirationFromInfo($info),
                'external_id' => $fqdn,
                'message' => 'Domain status synced from Cosmotown.',
                'raw' => ['status' => $statusRow, 'info' => $info],
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'expiration_date' => null,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'expiration_date' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function updateNameservers(Registrar $registrar, Domain $domain, array $nameServers): array
    {
        $hosts = $this->nameserverHostnames($nameServers);
        if (count($hosts) < 2) {
            return [
                'success' => false,
                'status' => 'FAI',
                'message' => 'At least two nameservers are required.',
            ];
        }

        try {
            CosmotownClient::forRegistrar($registrar)->saveDomainNameservers($this->fqdn($domain), $hosts);

            return [
                'success' => true,
                'status' => 'ACT',
                'message' => 'Nameservers updated at Cosmotown.',
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'status' => 'FAI',
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'FAI',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, auth_code?: string, message: string}
     */
    public function getDomainAuthCode(Registrar $registrar, Domain|string $domain): array
    {
        try {
            $fqdn = $domain instanceof Domain
                ? $this->fqdn($domain)
                : strtolower(trim($domain));

            $result = CosmotownClient::forRegistrar($registrar)->getDomainAuthCode($fqdn);

            return [
                'success' => true,
                'auth_code' => $result['auth_code'],
                'message' => 'Auth code retrieved from Cosmotown.',
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, response?: array<string, mixed>}
     */
    public function syncDefaultContacts(Registrar $registrar): array
    {
        try {
            $contacts = $this->defaultContactPayload($registrar);
            $response = CosmotownClient::forRegistrar($registrar)->saveContactInfo($contacts);

            return [
                'success' => true,
                'message' => 'Default contacts saved at Cosmotown.',
                'response' => $response,
            ];
        } catch (CosmotownException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{registrant: array<string, string>, administrative: array<string, string>, technical: array<string, string>, billing: array<string, string>}
     */
    public function defaultContactPayload(Registrar $registrar): array
    {
        $config = is_array($registrar->config) ? $registrar->config : [];
        $contact = [
            'FirstName' => trim((string) ($config['contact_first_name'] ?? '')),
            'LastName' => trim((string) ($config['contact_last_name'] ?? '')),
            'Company' => trim((string) ($config['contact_company'] ?? '')),
            'Phone' => trim((string) ($config['contact_phone'] ?? '')),
            'Extension' => '',
            'Fax' => '',
            'City' => trim((string) ($config['contact_city'] ?? '')),
            'State' => trim((string) ($config['contact_state'] ?? '')),
            'Zip' => trim((string) ($config['contact_zip'] ?? '')),
            'Country' => strtoupper(trim((string) ($config['contact_country'] ?? ''))),
            'Email' => trim((string) ($config['contact_email'] ?? '')),
            'Address1' => trim((string) ($config['contact_address1'] ?? '')),
            'Address2' => '',
        ];

        if ($contact['FirstName'] === '' || $contact['LastName'] === '' || $contact['Email'] === '') {
            throw new CosmotownException(
                'Configure Cosmotown default contact first name, last name, and email before registering domains.'
            );
        }

        return [
            'registrant' => $contact,
            'administrative' => $contact,
            'technical' => $contact,
            'billing' => $contact,
        ];
    }

    public static function parseExpiration(?string $value): ?Carbon
    {
        return OpenproviderRegistrarDriver::parseExpiration($value);
    }

    private function fqdn(Domain $domain): string
    {
        return strtolower(trim($domain->fqdn()));
    }

    /**
     * @param  list<array{name: string}>|list<string>  $nameServers
     * @return list<string>
     */
    private function nameserverHostnames(array $nameServers): array
    {
        $hosts = [];
        $seen = [];

        foreach ($nameServers as $ns) {
            $name = is_array($ns) ? trim((string) ($ns['name'] ?? '')) : trim((string) $ns);
            $key = strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hosts[] = $name;
        }

        return $hosts;
    }

    private function ensureDefaultContacts(Registrar $registrar, CosmotownClient $client): void
    {
        $config = is_array($registrar->config) ? $registrar->config : [];
        if (trim((string) ($config['contact_first_name'] ?? '')) === '') {
            return;
        }

        $client->saveContactInfo($this->defaultContactPayload($registrar));
    }

    /**
     * @param  list<array{name: string}>|list<string>  $nameServers
     */
    private function pushNameserversQuietly(CosmotownClient $client, string $fqdn, array $nameServers): void
    {
        $hosts = $this->nameserverHostnames($nameServers);
        if (count($hosts) < 2) {
            return;
        }

        try {
            $client->saveDomainNameservers($fqdn, $hosts);
        } catch (\Throwable) {
            // Registration/transfer was accepted; nameservers can be retried from the domain page.
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowForDomain(array $rows, string $fqdn): array
    {
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row['domain'] ?? $row['name'] ?? '')));
            if ($name === $fqdn) {
                return $row;
            }
        }

        return $rows[0] ?? [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{success: bool, status: string, external_id: ?string, auth_code: ?string, expiration_date: ?string, message: string}
     */
    private function mapLifecycleRow(array $row, string $defaultMessage): array
    {
        $rawStatus = strtolower((string) ($row['status'] ?? $row['registration_status'] ?? ''));
        $failed = $rawStatus !== '' && preg_match('/fail|error|reject|invalid|insufficient/', $rawStatus) === 1;
        $message = $this->rowMessage($row, $defaultMessage);

        if ($failed) {
            return [
                'success' => false,
                'status' => 'FAI',
                'external_id' => null,
                'auth_code' => null,
                'expiration_date' => null,
                'message' => $message,
            ];
        }

        return [
            'success' => true,
            'status' => 'REQ',
            'external_id' => null,
            'auth_code' => isset($row['auth_code']) ? (string) $row['auth_code'] : null,
            'expiration_date' => $this->expirationFromInfo($row),
            'message' => $defaultMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function enrichFromDomainInfo(CosmotownClient $client, string $fqdn, array $mapped): array
    {
        try {
            $info = $client->getDomainInfo($fqdn);
            $expiry = $this->expirationFromInfo($info);
            if ($expiry) {
                $mapped['expiration_date'] = $expiry;
            }
        } catch (\Throwable) {
            return $mapped;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function expirationFromInfo(array $info): ?string
    {
        foreach (['expiration_date', 'expiry_date', 'expires'] as $key) {
            $value = $info[$key] ?? ($info['domain'][$key] ?? null);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function statusFromInfo(array $info): string
    {
        return strtolower((string) ($info['registration_status'] ?? $info['status'] ?? ''));
    }

    private function mapCosmotownStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if ($status === '' || preg_match('/pending|process|queue|transfer|request/', $status) === 1) {
            return 'REQ';
        }

        if (preg_match('/fail|error|reject/', $status) === 1) {
            return 'FAI';
        }

        if (preg_match('/active|registered|ok|complete|success/', $status) === 1) {
            return 'ACT';
        }

        return 'REQ';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMessage(array $row, string $fallback): string
    {
        foreach (['message', 'error_message', 'error', 'status'] as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    /**
     * @return array{success: bool, status: string, external_id: null, auth_code: null, expiration_date: null, message: string}
     */
    private function failedLifecycle(string $message): array
    {
        return [
            'success' => false,
            'status' => 'FAI',
            'external_id' => null,
            'auth_code' => null,
            'expiration_date' => null,
            'message' => $message,
        ];
    }
}
