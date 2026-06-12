<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Services\Registrar\Openprovider\OpenproviderException;
use App\Services\Registrar\RegistrarFulfillmentService;
use App\Services\Registrar\RegistrarManager;
use App\Services\Registrar\RegistrarOperationsInterface;
use Illuminate\Support\Str;

class DomainAvailabilityService
{
    private const WHOIS_TIMEOUT = 8;

    /** @var list<string> */
    private const AVAILABLE_PATTERNS = [
        'no match for',
        'domain not found',
        'not found in the registry',
        'no data found',
        'no entries found',
        'object does not exist',
        'no matching record',
        'status: free',
        'available for registration',
    ];

    public function __construct(
        private DomainInputParser $parser,
        private RegistrarManager $registrarManager,
        private RegistrarFulfillmentService $registrarFulfillment,
    ) {}

    /**
     * @param  array<int, string>|null  $allowedExtensions
     * @return array{available: bool, full_domain: string, name: string, extension: string, source: string}|null
     */
    public function checkInput(string $input, ?string $extensionInput = null, ?array $allowedExtensions = null): ?array
    {
        $allowedExtensions ??= DomainExtension::query()
            ->where('enabled', true)
            ->pluck('extension')
            ->all();

        $parsed = $this->parser->parse($input, $extensionInput, $allowedExtensions);

        if ($parsed === null) {
            return null;
        }

        $fullDomain = $parsed['name'].$parsed['extension'];
        $available = $this->isAvailable($parsed['name'], $parsed['extension'], $fullDomain);

        return [
            'available' => $available,
            'full_domain' => $fullDomain,
            'name' => $parsed['name'],
            'extension' => $parsed['extension'],
            'source' => $this->lastSource,
        ];
    }

    private string $lastSource = 'unknown';

    public function isAvailable(string $name, string $extension, ?string $fullDomain = null): bool
    {
        $fullDomain ??= $name.$extension;
        $this->lastSource = 'unknown';

        if ($this->isRegisteredLocally($name, $extension)) {
            $this->lastSource = 'local';

            return false;
        }

        $domainExtension = DomainExtension::where('extension', $extension)->first();
        if ($domainExtension && $this->registrarFulfillment->usesOpenprovider($domainExtension)) {
            $registrar = $this->registrarManager->forExtension($domainExtension);
            $driver = $this->registrarManager->driver($registrar);

            if ($driver instanceof RegistrarOperationsInterface) {
                try {
                    $result = $driver->checkAvailability($registrar, $name, $extension);
                    $this->lastSource = $result['source'] ?? 'openprovider';

                    return (bool) ($result['available'] ?? false);
                } catch (OpenproviderException $e) {
                    $this->lastSource = 'openprovider-error';
                    \Log::warning('Openprovider availability check failed, falling back to WHOIS', [
                        'domain' => $fullDomain,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $whoisResult = $this->checkWhois($fullDomain, $extension);

        if ($whoisResult !== null) {
            $this->lastSource = 'whois';

            return $whoisResult;
        }

        $this->lastSource = 'dns';

        return $this->checkDns($fullDomain);
    }

    private function isRegisteredLocally(string $name, string $extension): bool
    {
        return Domain::query()
            ->where('name', $name)
            ->where('extension', $extension)
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->exists();
    }

    private function checkWhois(string $fullDomain, string $extension): ?bool
    {
        $response = $this->queryWhois($fullDomain, $this->resolveWhoisServer($extension));

        if ($response === '') {
            return null;
        }

        $interpreted = $this->interpretWhoisResponse($response);

        if ($interpreted !== null) {
            return $interpreted;
        }

        if (preg_match('/registrar whois server:\s*(\S+)/i', $response, $matches)) {
            $referral = strtolower(rtrim($matches[1], '.'));

            if ($referral !== '' && ! str_contains($referral, 'verisign')) {
                $referralResponse = $this->queryWhois($fullDomain, $referral);
                $referralResult = $referralResponse !== ''
                    ? $this->interpretWhoisResponse($referralResponse)
                    : null;

                if ($referralResult !== null) {
                    return $referralResult;
                }
            }
        }

        return null;
    }

    private function queryWhois(string $domain, string $server): string
    {
        $connection = @fsockopen($server, 43, $errno, $errstr, self::WHOIS_TIMEOUT);

        if (! $connection) {
            return '';
        }

        fwrite($connection, $domain."\r\n");

        $response = '';

        while (! feof($connection)) {
            $chunk = fgets($connection, 1024);

            if ($chunk === false) {
                break;
            }

            $response .= $chunk;
        }

        fclose($connection);

        return $response;
    }

    private function interpretWhoisResponse(string $response): ?bool
    {
        $lower = strtolower($response);

        if ($this->hasRegistrationIndicators($lower)) {
            return false;
        }

        foreach (self::AVAILABLE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return null;
    }

    private function hasRegistrationIndicators(string $lower): bool
    {
        if (! str_contains($lower, 'domain name:')) {
            return false;
        }

        return str_contains($lower, 'creation date:')
            || str_contains($lower, 'registry expiry date:')
            || str_contains($lower, 'registrar:');
    }

    private function resolveWhoisServer(string $extension): string
    {
        return match ($extension) {
            '.com', '.net' => 'whois.verisign.com',
            '.org' => 'whois.pir.org',
            '.io' => 'whois.nic.io',
            '.co.ke' => 'whois.kenic.or.ke',
            default => 'whois.nic.'.ltrim(Str::afterLast($extension, '.'), '.'),
        };
    }

    private function checkDns(string $domain): bool
    {
        try {
            $ip = gethostbyname($domain);

            return $ip === $domain;
        } catch (\Throwable) {
            return true;
        }
    }
}
