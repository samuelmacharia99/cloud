<?php

namespace App\Services\Registrar\Cosmotown;

class CosmotownTldPrice
{
    private const NESTED_BAG_KEYS = [
        'data', 'result', 'response', 'pricing', 'prices', 'price',
        'tld', 'tldprice', 'tld_price', 'costs', 'cost',
        'resultdata', 'result_data', 'body', 'content', 'payload',
        'item', 'record', 'tlds',
    ];

    public function __construct(
        public readonly string $tld,
        public readonly ?float $registerUsd,
        public readonly ?float $renewUsd,
        public readonly ?float $transferUsd,
        public readonly string $currency = 'USD',
    ) {}

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    public static function fromPayload(string $tld, array $payload): self
    {
        $tld = self::normalizeTld($tld);
        $payload = self::unwrapRawJson($payload);
        $amounts = self::extractAmounts($payload, $tld);

        if ($amounts['register'] === null && $amounts['renew'] === null && $amounts['transfer'] === null) {
            throw new CosmotownException(self::parseFailureMessage($payload), 200, $payload);
        }

        return new self(
            tld: $tld,
            registerUsd: $amounts['register'],
            renewUsd: $amounts['renew'] ?? $amounts['register'],
            transferUsd: $amounts['transfer'] ?? $amounts['renew'] ?? $amounts['register'],
            currency: $amounts['currency'],
        );
    }

    /**
     * Parse a Cosmotown catalog (all TLDs in one body) into per-TLD prices.
     *
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, self>
     */
    public static function catalogFromPayload(array $payload): array
    {
        $payload = self::unwrapRawJson($payload);
        $catalog = [];

        foreach (self::catalogRoots($payload) as $root) {
            if (array_is_list($root)) {
                foreach ($root as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $rowTld = self::normalizeTld((string) (
                        $row['tld'] ?? $row['extension'] ?? $row['name'] ?? $row['Tld'] ?? ''
                    ));

                    if ($rowTld === '' || ! self::looksLikeTld($rowTld)) {
                        continue;
                    }

                    try {
                        $catalog[$rowTld] = self::fromPayload($rowTld, $row);
                    } catch (CosmotownException) {
                    }
                }

                continue;
            }

            foreach ($root as $key => $value) {
                if (! is_string($key) || ! is_array($value)) {
                    continue;
                }

                $keyTld = self::normalizeTld($key);
                if (! self::looksLikeTld($keyTld)) {
                    continue;
                }

                try {
                    $catalog[$keyTld] = self::fromPayload($keyTld, $value);
                } catch (CosmotownException) {
                }
            }
        }

        return $catalog;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array{register: ?float, renew: ?float, transfer: ?float, currency: string}
     */
    private static function extractAmounts(array $payload, string $tld): array
    {
        $register = null;
        $renew = null;
        $transfer = null;
        $currency = 'USD';

        foreach (self::collectBags($payload, $tld) as $bag) {
            $register ??= self::amountFromBag($bag, 'register');
            $renew ??= self::amountFromBag($bag, 'renew');
            $transfer ??= self::amountFromBag($bag, 'transfer');
            self::applyTypedRow($bag, $register, $renew, $transfer);

            $currencyCandidate = self::currencyFromBag($bag);
            if ($currencyCandidate !== null) {
                $currency = $currencyCandidate;
            }
        }

        if ($register === null && $renew === null && $transfer === null) {
            $register = self::genericPrice($payload);
        }

        return [
            'register' => $register,
            'renew' => $renew,
            'transfer' => $transfer,
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function collectBags(array $payload, string $tld): array
    {
        $bags = [];
        self::walkBags($payload, $tld, $bags, 0);

        return $bags;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @param  list<array<string, mixed>>  $bags
     */
    private static function walkBags(array $node, string $tld, array &$bags, int $depth): void
    {
        if ($depth > 6) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $row) {
                if (is_array($row)) {
                    self::walkBags($row, $tld, $bags, $depth + 1);
                }
            }

            return;
        }

        $bags[] = $node;

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $keyStr = strtolower(str_replace(['-', ' '], '_', (string) $key));
            $keyTld = is_string($key) ? self::normalizeTld($key) : '';
            $isKnownBag = in_array($keyStr, self::NESTED_BAG_KEYS, true);
            $isMatchingTld = $keyTld !== '' && $keyTld === $tld && self::looksLikeTld($keyTld);
            $isYear = $keyStr === '1' || $key === 1;

            if ($isKnownBag || $isMatchingTld || $isYear) {
                self::walkBags($value, $tld, $bags, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function amountFromBag(array $bag, string $kind): ?float
    {
        foreach ($bag as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $normalizedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));
            if (! self::keyMatchesKind($normalizedKey, $kind)) {
                continue;
            }

            $amount = self::normalizeAmount($value);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function applyTypedRow(array $bag, ?float &$register, ?float &$renew, ?float &$transfer): void
    {
        $type = strtolower(trim((string) ($bag['type'] ?? $bag['action'] ?? $bag['operation'] ?? $bag['kind'] ?? '')));
        if ($type === '') {
            return;
        }

        $amount = self::normalizeAmount($bag['price'] ?? $bag['amount'] ?? $bag['cost'] ?? $bag['value'] ?? null);
        if ($amount === null) {
            return;
        }

        if ($register === null && self::keyMatchesKind($type, 'register')) {
            $register = $amount;
        }
        if ($renew === null && self::keyMatchesKind($type, 'renew')) {
            $renew = $amount;
        }
        if ($transfer === null && self::keyMatchesKind($type, 'transfer')) {
            $transfer = $amount;
        }
    }

    private static function keyMatchesKind(string $key, string $kind): bool
    {
        return match ($kind) {
            'register' => self::isRegisterKey($key),
            'renew' => self::isRenewKey($key),
            'transfer' => self::isTransferKey($key),
            default => false,
        };
    }

    private static function isRegisterKey(string $key): bool
    {
        if (self::isRenewKey($key) || self::isTransferKey($key) || self::isRestoreKey($key)) {
            return false;
        }

        return in_array($key, [
            'register', 'registration', 'create', 'reg', 'new', 'add',
            'first_year', 'firstyear', 'regprice', 'createprice', 'addprice',
            'registrationprice', 'registerprice', 'price_register',
            'registration_price', 'register_price', 'create_price',
            'registrationfee', 'registerfee',
        ], true)
            || str_contains($key, 'register')
            || str_contains($key, 'registration')
            || (str_contains($key, 'create') && str_contains($key, 'price'));
    }

    private static function isRenewKey(string $key): bool
    {
        if (self::isRestoreKey($key)) {
            return false;
        }

        return in_array($key, [
            'renew', 'renewal', 'renew_price', 'renewalprice', 'renewprice',
            'price_renew', 'renewal_price', 'renewalfee', 'renewfee',
        ], true)
            || str_contains($key, 'renew');
    }

    private static function isTransferKey(string $key): bool
    {
        return in_array($key, [
            'transfer', 'transfer_price', 'transferprice', 'price_transfer',
            'transprice', 'transferfee',
        ], true)
            || str_contains($key, 'transfer')
            || str_contains($key, 'transprice');
    }

    private static function isRestoreKey(string $key): bool
    {
        return str_contains($key, 'restore')
            || str_contains($key, 'redemption')
            || str_contains($key, 'grace');
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private static function genericPrice(array $payload): ?float
    {
        if (array_is_list($payload)) {
            return null;
        }

        foreach (['price', 'amount', 'cost', 'wholesale', 'wholesale_price'] as $key) {
            $amount = self::normalizeAmount($payload[$key] ?? null);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function currencyFromBag(array $bag): ?string
    {
        foreach (['currency', 'currency_code', 'currencycode', 'curr'] as $key) {
            $value = $bag[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }

        return null;
    }

    private static function normalizeAmount(mixed $value, int $depth = 0): ?float
    {
        if ($depth > 5) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $amount = (float) $value;

            return $amount < 0 ? null : $amount;
        }

        if (is_string($value)) {
            $normalized = trim(str_replace([',', '$'], '', $value));
            $normalized = (string) preg_replace('/^(usd|kes|eur)\s*/i', '', $normalized);
            $normalized = (string) preg_replace('/\s*(usd|kes|eur)$/i', '', $normalized);

            if ($normalized === '' || ! is_numeric($normalized)) {
                return null;
            }

            $amount = (float) $normalized;

            return $amount < 0 ? null : $amount;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        foreach (['amount', 'price', 'cost', 'value', 'usd', 'USD', 'wholesale', 'reseller', 'product'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $inner = self::normalizeAmount($value[$key], $depth + 1);
            if ($inner !== null) {
                return $inner;
            }
        }

        foreach (['1', 1] as $year) {
            if (array_key_exists($year, $value)) {
                $yearAmount = self::normalizeAmount($value[$year], $depth + 1);
                if ($yearAmount !== null) {
                    return $yearAmount;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private static function unwrapRawJson(array $payload): array
    {
        if (isset($payload['raw']) && is_string($payload['raw']) && count($payload) === 1) {
            $decoded = json_decode($payload['raw'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<array<string, mixed>|list<mixed>>
     */
    private static function catalogRoots(array $payload): array
    {
        $roots = [$payload];

        if (array_is_list($payload)) {
            return $roots;
        }

        foreach (['data', 'result', 'response', 'pricing', 'prices', 'tlds', 'resultdata', 'result_data'] as $key) {
            foreach ($payload as $payloadKey => $value) {
                if (is_string($payloadKey) && strtolower($payloadKey) === $key && is_array($value)) {
                    $roots[] = $value;
                }
            }
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private static function parseFailureMessage(array $payload): string
    {
        $keys = self::topLevelKeys($payload);
        $keyList = $keys === [] ? '(empty body)' : implode(', ', array_slice($keys, 0, 12));

        $message = 'Cosmotown tldprice response did not include registration, renewal, or transfer amounts. Response keys: '.$keyList.'.';

        if (self::looksLikeContactPayload($payload)) {
            $message .= ' Cosmotown returned contact fields instead of TLD prices.';
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<string>
     */
    private static function topLevelKeys(array $payload): array
    {
        if (array_is_list($payload)) {
            return ['[list]'];
        }

        return array_map('strval', array_keys($payload));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private static function looksLikeContactPayload(array $payload): bool
    {
        $haystack = strtolower(implode(' ', self::topLevelKeys($payload)));

        return str_contains($haystack, 'registrant')
            || str_contains($haystack, 'firstname')
            || str_contains($haystack, 'contact');
    }

    private static function looksLikeTld(string $tld): bool
    {
        if ($tld === '' || strlen($tld) > 24) {
            return false;
        }

        $reserved = [
            'data', 'result', 'response', 'pricing', 'prices', 'price', 'tld',
            'tldprice', 'costs', 'cost', 'currency', 'register', 'renew',
            'transfer', 'registration', 'renewal', 'message', 'error', 'status',
            'success', 'item', 'items', 'domains', 'raw',
        ];

        if (in_array($tld, $reserved, true)) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/', $tld);
    }

    public static function normalizeTld(string $tld): string
    {
        return ltrim(strtolower(trim($tld)), '.');
    }
}
