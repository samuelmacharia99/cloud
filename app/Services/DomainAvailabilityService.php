<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Services\Registrar\Openprovider\OpenproviderException;
use App\Services\Registrar\RegistrarFulfillmentService;
use App\Services\Registrar\RegistrarManager;
use App\Services\Registrar\RegistrarOperationsInterface;
use Illuminate\Support\Facades\Http;
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

    /** @var list<string> */
    private const PREMIUM_PATTERNS = [
        'premium domain',
        'premium name',
        'this name is premium',
        'is a premium',
        'premium priced',
    ];

    /** @var list<string> */
    private const RESERVED_PATTERNS = [
        'reserved by the registry',
        'reserved name',
        'this name is reserved',
        'not available for registration',
        'blocked by the registry',
        'registry reserved',
    ];

    public function __construct(
        private DomainInputParser $parser,
        private RegistrarManager $registrarManager,
        private RegistrarFulfillmentService $registrarFulfillment,
    ) {}

    /**
     * @param  array<int, string>|null  $allowedExtensions
     * @return array{
     *     available: bool,
     *     full_domain: string,
     *     name: string,
     *     extension: string,
     *     source: string,
     *     blocked_reason: ?string
     * }|null
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
        $inspect = $this->inspect($parsed['name'], $parsed['extension'], $fullDomain);

        return [
            'available' => $inspect['available'],
            'full_domain' => $fullDomain,
            'name' => $parsed['name'],
            'extension' => $parsed['extension'],
            'source' => $inspect['source'],
            'blocked_reason' => $inspect['blocked_reason'],
        ];
    }

    /**
     * @return array{available: bool, source: string, blocked_reason: ?string}
     */
    public function inspect(string $name, string $extension, ?string $fullDomain = null): array
    {
        $fullDomain ??= $name.$extension;
        $this->lastSource = 'unknown';
        $this->lastBlockedReason = null;

        if ($this->isRegisteredLocally($name, $extension)) {
            $this->lastSource = 'local';
            $this->lastBlockedReason = 'taken';

            return $this->inspectResult(false);
        }

        $domainExtension = DomainExtension::where('extension', $extension)->first();
        if ($domainExtension && $this->registrarFulfillment->usesOpenprovider($domainExtension)) {
            $registrar = $this->registrarManager->forExtension($domainExtension);
            $driver = $this->registrarManager->driver($registrar);

            if ($driver instanceof RegistrarOperationsInterface) {
                try {
                    $result = $driver->checkAvailability($registrar, $name, $extension);
                    $this->lastSource = $result['source'] ?? 'openprovider';
                    $available = (bool) ($result['available'] ?? false);
                    if (! $available && ! empty($result['is_premium'])) {
                        $this->lastBlockedReason = 'premium';
                    } elseif (! $available) {
                        $this->lastBlockedReason = 'taken';
                    }

                    return $this->inspectResult($available);
                } catch (OpenproviderException $e) {
                    $this->lastSource = 'openprovider-error';
                    \Log::warning('Openprovider availability check failed, falling back to WHOIS', [
                        'domain' => $fullDomain,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $whoisResult = $this->checkWhoisDetailed($fullDomain, $extension);
        if ($whoisResult !== null) {
            $this->lastSource = 'whois';
            $this->lastBlockedReason = $whoisResult['reason'];

            return $this->inspectResult($whoisResult['available']);
        }

        $rdap = $this->checkRdap($fullDomain);
        if ($rdap !== null) {
            $this->lastSource = 'rdap';
            $this->lastBlockedReason = $rdap ? null : 'taken';

            return $this->inspectResult($rdap);
        }

        $this->lastSource = 'dns';
        $available = $this->checkDns($fullDomain);
        $this->lastBlockedReason = $available ? null : 'taken';

        return $this->inspectResult($available);
    }

    public function registrationBlockMessage(array $check): ?string
    {
        if ($check['available'] ?? false) {
            return null;
        }

        return match ($check['blocked_reason'] ?? 'taken') {
            'premium' => 'This name is a premium domain. We cannot sell it at standard TLD pricing — contact support.',
            'reserved' => 'This name is reserved at the registry and cannot be registered at standard pricing.',
            default => 'This domain is not available for registration.',
        };
    }

    private string $lastSource = 'unknown';

    private ?string $lastBlockedReason = null;

    public function isAvailable(string $name, string $extension, ?string $fullDomain = null): bool
    {
        return $this->inspect($name, $extension, $fullDomain)['available'];
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
        $detailed = $this->checkWhoisDetailed($fullDomain, $extension);

        return $detailed['available'] ?? null;
    }

    /**
     * @return array{available: bool, reason: ?string}|null
     */
    private function checkWhoisDetailed(string $fullDomain, string $extension): ?array
    {
        $response = $this->queryWhois($fullDomain, $this->resolveWhoisServer($extension));

        if ($response === '') {
            return null;
        }

        $interpreted = $this->interpretWhoisDetailed($response);

        if ($interpreted !== null) {
            return $interpreted;
        }

        if (preg_match('/registrar whois server:\s*(\S+)/i', $response, $matches)) {
            $referral = strtolower(rtrim($matches[1], '.'));

            if ($referral !== '' && ! str_contains($referral, 'verisign')) {
                $referralResponse = $this->queryWhois($fullDomain, $referral);
                $referralResult = $referralResponse !== ''
                    ? $this->interpretWhoisDetailed($referralResponse)
                    : null;

                if ($referralResult !== null) {
                    return $referralResult;
                }
            }
        }

        return null;
    }

    /**
     * @return array{available: bool, reason: ?string}|null
     */
    private function interpretWhoisDetailed(string $response): ?array
    {
        $lower = strtolower($response);

        foreach (self::PREMIUM_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return ['available' => false, 'reason' => 'premium'];
            }
        }

        foreach (self::RESERVED_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return ['available' => false, 'reason' => 'reserved'];
            }
        }

        $available = $this->interpretWhoisResponse($response);
        if ($available === null) {
            return null;
        }

        return [
            'available' => $available,
            'reason' => $available ? null : 'taken',
        ];
    }

    /**
     * RDAP: 404 usually means unregistered; 200 means registered. Cosmotown has no availability API.
     */
    private function checkRdap(string $fullDomain): ?bool
    {
        try {
            $response = Http::timeout(6)
                ->acceptJson()
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->get('https://rdap.org/domain/'.$fullDomain);

            if ($response->status() === 404) {
                return true;
            }

            if ($response->successful()) {
                return false;
            }
        } catch (\Throwable $e) {
            \Log::info('RDAP availability lookup skipped', [
                'domain' => $fullDomain,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return array{available: bool, source: string, blocked_reason: ?string}
     */
    private function inspectResult(bool $available): array
    {
        return [
            'available' => $available,
            'source' => $this->lastSource,
            'blocked_reason' => $available ? null : $this->lastBlockedReason,
        ];
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
