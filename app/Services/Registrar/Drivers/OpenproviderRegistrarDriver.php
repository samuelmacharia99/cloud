<?php

namespace App\Services\Registrar\Drivers;

use App\Models\Domain;
use App\Models\Registrar;
use App\Services\Registrar\Openprovider\OpenproviderClient;
use App\Services\Registrar\Openprovider\OpenproviderException;
use App\Services\Registrar\RegistrarOperationsInterface;
use Illuminate\Support\Carbon;

class OpenproviderRegistrarDriver implements RegistrarOperationsInterface
{
    public function testConnection(Registrar $registrar): array
    {
        try {
            $client = OpenproviderClient::forRegistrar($registrar);
            $client->login(true);
            $reseller = $client->getReseller();
            $data = $reseller['data'] ?? [];
            $balance = $data['balance'] ?? null;
            $currency = $data['settings']['currency'] ?? ($data['currency'] ?? '');
            $resellerId = $data['id'] ?? ($client->login()['reseller_id'] ?? null);

            $balanceText = $balance !== null
                ? ' Balance: '.number_format((float) $balance, 2).' '.($currency ?: '')
                : '';

            return [
                'success' => true,
                'message' => "Connected to Openprovider (reseller #{$resellerId}).{$balanceText}",
                'reseller_id' => $resellerId,
                'balance' => $balance,
            ];
        } catch (OpenproviderException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'api_code' => $e->apiCode,
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
        $client = OpenproviderClient::forRegistrar($registrar);
        $results = $client->checkDomains([
            ['name' => strtolower($name), 'extension' => $extension],
        ], $withPrice);

        $row = $results[0] ?? [];

        return [
            'available' => ($row['status'] ?? '') === 'free',
            'status' => $row['status'] ?? null,
            'price' => $row['price'] ?? null,
            'premium' => $row['premium'] ?? null,
            'is_premium' => (bool) ($row['is_premium'] ?? false),
            'source' => 'openprovider',
        ];
    }

    public function registerDomain(Registrar $registrar, Domain $domain, int $years, array $nameServers): array
    {
        $client = OpenproviderClient::forRegistrar($registrar);
        $handles = $this->contactHandles($registrar);

        $payload = [
            'owner_handle' => $handles['owner'],
            'admin_handle' => $handles['admin'],
            'tech_handle' => $handles['tech'],
            'billing_handle' => $handles['billing'],
            'domain' => [
                'name' => strtolower($domain->name),
                'extension' => OpenproviderClient::normalizeExtension($domain->extension),
            ],
            'period' => max(1, $years),
            'autorenew' => 'off',
            'name_servers' => $nameServers,
        ];

        $response = $client->registerDomain($payload);
        $data = $response['data'] ?? [];

        return $this->mapOperationResult($data, 'Domain registration submitted.');
    }

    public function transferDomain(Registrar $registrar, Domain $domain, string $authCode, array $nameServers): array
    {
        $client = OpenproviderClient::forRegistrar($registrar);
        $handles = $this->contactHandles($registrar);

        $payload = [
            'owner_handle' => $handles['owner'],
            'admin_handle' => $handles['admin'],
            'tech_handle' => $handles['tech'],
            'auth_code' => $authCode,
            'domain' => [
                'name' => strtolower($domain->name),
                'extension' => OpenproviderClient::normalizeExtension($domain->extension),
            ],
            'autorenew' => 'off',
            'name_servers' => $nameServers,
        ];

        $response = $client->transferDomain($payload);
        $data = $response['data'] ?? [];

        return $this->mapOperationResult($data, 'Domain transfer submitted.');
    }

    public function renewDomain(Registrar $registrar, Domain $domain, int $years): array
    {
        if (! $domain->registrar_external_id) {
            return [
                'success' => false,
                'status' => 'FAI',
                'expiration_date' => null,
                'message' => 'Domain has no Openprovider ID — cannot renew via API.',
            ];
        }

        $client = OpenproviderClient::forRegistrar($registrar);
        $response = $client->renewDomain((int) $domain->registrar_external_id, max(1, $years));
        $data = $response['data'] ?? [];

        return [
            'success' => in_array($data['status'] ?? '', ['ACT', 'REQ'], true),
            'status' => (string) ($data['status'] ?? 'REQ'),
            'expiration_date' => $data['expiration_date'] ?? null,
            'message' => 'Domain renewal submitted.',
        ];
    }

    public function syncDomainStatus(Registrar $registrar, Domain $domain): array
    {
        $client = OpenproviderClient::forRegistrar($registrar);

        if ($domain->registrar_external_id) {
            $response = $client->getDomain((int) $domain->registrar_external_id);
            $data = $response['data'] ?? [];

            return [
                'success' => true,
                'status' => (string) ($data['status'] ?? ''),
                'expiration_date' => $data['expiration_date'] ?? $data['registry_expiration_date'] ?? null,
                'external_id' => (int) ($data['id'] ?? $domain->registrar_external_id),
                'message' => 'Domain status synced.',
                'raw' => $data,
            ];
        }

        $search = $client->searchDomain($domain->name, $domain->extension);
        $data = $search['data']['results'][0] ?? [];

        if ($data === []) {
            return [
                'success' => false,
                'status' => '',
                'expiration_date' => null,
                'message' => 'Domain not found at Openprovider.',
            ];
        }

        return [
            'success' => true,
            'status' => (string) ($data['status'] ?? ''),
            'expiration_date' => $data['expiration_date'] ?? $data['registry_expiration_date'] ?? null,
            'external_id' => isset($data['id']) ? (int) $data['id'] : null,
            'message' => 'Domain located at Openprovider.',
            'raw' => $data,
        ];
    }

    /**
     * @return array{owner: string, admin: string, tech: string, billing: string}
     */
    private function contactHandles(Registrar $registrar): array
    {
        $config = $registrar->config ?? [];
        $owner = trim((string) ($config['owner_handle'] ?? ''));
        $admin = trim((string) ($config['admin_handle'] ?? ''));
        $tech = trim((string) ($config['tech_handle'] ?? ''));
        $billing = trim((string) ($config['billing_handle'] ?? ''));

        if ($owner === '') {
            throw new OpenproviderException('Configure platform contact handles (owner_handle) on the Openprovider registrar.');
        }

        $admin = $admin !== '' ? $admin : $owner;
        $tech = $tech !== '' ? $tech : $owner;
        $billing = $billing !== '' ? $billing : $owner;

        return compact('owner', 'admin', 'tech', 'billing');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, status: string, external_id: ?int, auth_code: ?string, expiration_date: ?string, message: string}
     */
    private function mapOperationResult(array $data, string $defaultMessage): array
    {
        $status = strtoupper((string) ($data['status'] ?? 'REQ'));
        $success = in_array($status, ['ACT', 'REQ'], true);
        $message = $success
            ? $defaultMessage
            : OpenproviderClient::formatOperationFailure($data, $defaultMessage);

        return [
            'success' => $success,
            'status' => $status,
            'external_id' => isset($data['id']) ? (int) $data['id'] : null,
            'auth_code' => isset($data['auth_code']) ? (string) $data['auth_code'] : null,
            'expiration_date' => $data['expiration_date'] ?? null,
            'message' => $message,
        ];
    }

    public static function parseExpiration(?string $value): ?Carbon
    {
        if (! $value || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
