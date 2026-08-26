<?php

namespace App\Services\Registrar\Cosmotown;

class CosmotownTldPrice
{
    public function __construct(
        public readonly string $tld,
        public readonly ?float $registerUsd,
        public readonly ?float $renewUsd,
        public readonly ?float $transferUsd,
        public readonly string $currency = 'USD',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $tld, array $payload): self
    {
        $bags = self::payloadBags($payload);

        $register = self::firstAmount($bags, [
            'register', 'registration', 'create', 'reg', 'new', 'first_year', 'firstYear',
            'Register', 'RegistrationPrice', 'register_price', 'registration_price',
            'RegisterPrice', 'price_register', 'registerPrice',
        ]);

        $renew = self::firstAmount($bags, [
            'renew', 'renewal', 'renew_price', 'RenewalPrice', 'renewal_price',
            'RenewPrice', 'price_renew', 'renewPrice', 'renewalPrice',
        ]);

        $transfer = self::firstAmount($bags, [
            'transfer', 'transfer_price', 'TransferPrice', 'price_transfer', 'transferPrice',
        ]);

        $currency = strtoupper(trim((string) (
            self::firstString($bags, ['currency', 'Currency', 'currency_code', 'currencyCode'])
            ?? 'USD'
        )));

        if ($register === null && $renew === null && $transfer === null) {
            throw new CosmotownException(
                'Cosmotown tldprice response did not include registration, renewal, or transfer amounts.',
                200,
                $payload
            );
        }

        return new self(
            tld: self::normalizeTld($tld),
            registerUsd: $register,
            renewUsd: $renew ?? $register,
            transferUsd: $transfer ?? $renew ?? $register,
            currency: $currency !== '' ? $currency : 'USD',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function payloadBags(array $payload): array
    {
        $bags = [$payload];

        foreach (['data', 'result', 'response', 'pricing', 'prices', 'tld', 'tldprice'] as $key) {
            $nested = $payload[$key] ?? null;
            if (is_array($nested)) {
                $bags[] = $nested;
            }
        }

        return $bags;
    }

    /**
     * @param  list<array<string, mixed>>  $bags
     * @param  list<string>  $keys
     */
    private static function firstAmount(array $bags, array $keys): ?float
    {
        foreach ($bags as $bag) {
            foreach ($keys as $key) {
                $value = $bag[$key] ?? null;
                $amount = self::normalizeAmount($value);
                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $bags
     * @param  list<string>  $keys
     */
    private static function firstString(array $bags, array $keys): ?string
    {
        foreach ($bags as $bag) {
            foreach ($keys as $key) {
                $value = $bag[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    private static function normalizeAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace([',', '$'], '', $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    public static function normalizeTld(string $tld): string
    {
        return ltrim(strtolower(trim($tld)), '.');
    }
}
