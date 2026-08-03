<?php

namespace App\Services\Registrar\Drivers;

use App\Models\Domain;
use App\Models\Registrar;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\RegistrarOperationsInterface;

class CosmotownRegistrarDriver implements RegistrarOperationsInterface
{
    private const UNSUPPORTED = 'Cosmotown Reseller API V1.2 does not document this operation yet. Only domain EPP/auth codes and default contactinfo are available.';

    public function testConnection(Registrar $registrar): array
    {
        try {
            $client = CosmotownClient::forRegistrar($registrar);

            try {
                // Probe auth: a nonsense domain should yield 400 (bad request) when the token/IP are valid,
                // or 403 when credentials/whitelist fail.
                $client->getDomainAuthCode('connection-test.invalid');
            } catch (CosmotownException $e) {
                if ($e->httpStatus === 403) {
                    return [
                        'success' => false,
                        'message' => $e->getMessage(),
                        'http_status' => 403,
                    ];
                }

                if ($e->httpStatus === 400 || $e->httpStatus === 404) {
                    return [
                        'success' => true,
                        'message' => 'Connected to Cosmotown Reseller API (token accepted).',
                        'http_status' => $e->httpStatus,
                    ];
                }

                // Unexpected but authenticated responses — still report message.
                if ($e->httpStatus > 0 && $e->httpStatus < 500) {
                    return [
                        'success' => true,
                        'message' => 'Connected to Cosmotown Reseller API. Probe response: '.$e->getMessage(),
                        'http_status' => $e->httpStatus,
                    ];
                }

                throw $e;
            }

            return [
                'success' => true,
                'message' => 'Connected to Cosmotown Reseller API.',
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
        return false;
    }

    public function supportsTransfer(): bool
    {
        return false;
    }

    public function supportsRenewal(): bool
    {
        return false;
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
            'message' => self::UNSUPPORTED,
        ];
    }

    public function registerDomain(Registrar $registrar, Domain $domain, int $years, array $nameServers): array
    {
        return $this->unsupportedLifecycleResult();
    }

    public function transferDomain(Registrar $registrar, Domain $domain, string $authCode, array $nameServers): array
    {
        return $this->unsupportedLifecycleResult();
    }

    public function renewDomain(Registrar $registrar, Domain $domain, int $years): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'expiration_date' => null,
            'message' => self::UNSUPPORTED,
        ];
    }

    public function syncDomainStatus(Registrar $registrar, Domain $domain): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'expiration_date' => null,
            'message' => self::UNSUPPORTED,
        ];
    }

    public function updateNameservers(Registrar $registrar, Domain $domain, array $nameServers): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => self::UNSUPPORTED,
        ];
    }

    /**
     * @return array{success: bool, auth_code?: string, message: string}
     */
    public function getDomainAuthCode(Registrar $registrar, Domain|string $domain): array
    {
        try {
            $fqdn = $domain instanceof Domain
                ? strtolower(trim($domain->name.$domain->extension))
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
     * Push platform default contacts to Cosmotown (documented contactinfo endpoint).
     *
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
                'Configure Cosmotown default contact first name, last name, and email before syncing contacts.'
            );
        }

        return [
            'registrant' => $contact,
            'administrative' => $contact,
            'technical' => $contact,
            'billing' => $contact,
        ];
    }

    /**
     * @return array{success: bool, status: string, external_id: null, auth_code: null, expiration_date: null, message: string}
     */
    private function unsupportedLifecycleResult(): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'external_id' => null,
            'auth_code' => null,
            'expiration_date' => null,
            'message' => self::UNSUPPORTED,
        ];
    }
}
