<?php

namespace App\Support;

/**
 * Session cart keys for the customer portal vs reseller/public storefront.
 *
 * Portal (/my/cart) and storefront must not share a bag — clearing or syncing
 * one previously wiped the other because both used session key "cart".
 */
class SessionCart
{
    public const LEGACY_PORTAL_KEY = 'cart';

    public const STOREFRONT_KEY = 'storefront_cart';

    /**
     * Session key for the authenticated customer portal cart (per user).
     * Underscores only — Laravel session treats dots as nested array paths.
     */
    public static function portalKey(?int $userId = null): string
    {
        $userId ??= auth()->id();

        if ($userId) {
            return 'cart_u_'.$userId;
        }

        return self::LEGACY_PORTAL_KEY;
    }

    /**
     * Cart used by checkout on the current request (storefront host → storefront bag).
     */
    public static function activeKey(): string
    {
        if (app()->bound('currentReseller')) {
            return self::STOREFRONT_KEY;
        }

        return self::portalKey();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get(string $key): array
    {
        $cart = session($key);

        if ($cart === null && $key !== self::LEGACY_PORTAL_KEY && str_starts_with($key, 'cart_u_')) {
            $legacy = session(self::LEGACY_PORTAL_KEY);
            if (is_array($legacy) && $legacy !== []) {
                session([$key => $legacy]);
                session()->forget(self::LEGACY_PORTAL_KEY);

                return self::normalize($legacy);
            }
        }

        return self::normalize(is_array($cart) ? $cart : []);
    }

    /**
     * @param  array<string|int, mixed>  $cart
     */
    public static function put(string $key, array $cart): void
    {
        session([$key => self::normalize($cart)]);
    }

    public static function clear(string $key): void
    {
        session()->forget($key);

        // Keep legacy empty for older readers that still peek at "cart".
        if (str_starts_with($key, 'cart_u_')) {
            session()->forget(self::LEGACY_PORTAL_KEY);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function portal(): array
    {
        return self::get(self::portalKey());
    }

    /**
     * @param  array<string|int, mixed>  $cart
     */
    public static function putPortal(array $cart): void
    {
        self::put(self::portalKey(), $cart);
    }

    public static function clearPortal(): void
    {
        self::clear(self::portalKey());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function storefront(): array
    {
        return self::get(self::STOREFRONT_KEY);
    }

    /**
     * @param  array<string|int, mixed>  $cart
     */
    public static function putStorefront(array $cart): void
    {
        self::put(self::STOREFRONT_KEY, $cart);
    }

    public static function clearStorefront(): void
    {
        self::clear(self::STOREFRONT_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function active(): array
    {
        return self::get(self::activeKey());
    }

    /**
     * @param  array<string|int, mixed>  $cart
     */
    public static function putActive(array $cart): void
    {
        self::put(self::activeKey(), $cart);
    }

    public static function clearActive(): void
    {
        self::clear(self::activeKey());
    }

    /**
     * Append one line; returns the new line key.
     *
     * @param  array<string, mixed>  $item
     */
    public static function append(string $key, array $item, ?string $lineKey = null): string
    {
        $cart = self::get($key);
        $lineKey ??= uniqid('c_', true);
        $cart[$lineKey] = $item;
        self::put($key, $cart);

        return $lineKey;
    }

    /**
     * Merge incoming lines into an existing cart without dropping unrelated types.
     * Domain lines replace an existing line with the same full domain.
     *
     * @param  array<string, array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return array<string, array<string, mixed>>
     */
    public static function mergeIncoming(array $existing, array $incoming): array
    {
        $cart = self::normalize($existing);

        foreach ($incoming as $item) {
            if (! is_array($item) || ! isset($item['type'])) {
                continue;
            }

            $type = (string) $item['type'];
            $fullDomain = self::fullDomainFromItem($item);

            if (in_array($type, ['domain', 'domain_transfer'], true) && $fullDomain !== null) {
                foreach ($cart as $lineKey => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    if (self::fullDomainFromItem($line) === $fullDomain) {
                        unset($cart[$lineKey]);
                    }
                }
            }

            $cart[uniqid('c_', true)] = $item;
        }

        return $cart;
    }

    /**
     * Ensure cart lines use string keys (uniqid style), not a bare numeric list.
     *
     * @param  array<string|int, mixed>  $cart
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $cart): array
    {
        $normalized = [];

        foreach ($cart as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $lineKey = is_string($key) && $key !== '' ? $key : uniqid('c_', true);
            $normalized[$lineKey] = $item;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fullDomainFromItem(array $item): ?string
    {
        $full = $item['full_domain'] ?? null;
        if (is_string($full) && $full !== '') {
            return strtolower($full);
        }

        $domain = $item['domain'] ?? null;
        $extension = $item['extension'] ?? null;
        if (is_string($domain) && $domain !== '' && is_string($extension) && $extension !== '') {
            return strtolower($domain.$extension);
        }

        return null;
    }
}
