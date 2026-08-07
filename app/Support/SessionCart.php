<?php

namespace App\Support;

/**
 * Session cart keys for the customer portal vs reseller/public storefront.
 *
 * Portal (/my/cart) and storefront must not share a bag — clearing or syncing
 * one previously wiped the other because both used session key "cart".
 *
 * Cart line keys must never contain "." — Laravel request input/validation
 * treats dots as nested array paths (e.g. email_domain_mode.{cartKey}).
 */
class SessionCart
{
    public const LEGACY_PORTAL_KEY = 'cart';

    public const STOREFRONT_KEY = 'storefront_cart';

    /**
     * Flat cart line id safe for HTML form names and Laravel validation.
     */
    public static function newLineKey(string $prefix = 'c'): string
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?: 'c';

        return $prefix.'_'.bin2hex(random_bytes(8));
    }

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
                $normalized = self::normalize($legacy);
                session([$key => $normalized]);
                session()->forget(self::LEGACY_PORTAL_KEY);

                return $normalized;
            }
        }

        $raw = is_array($cart) ? $cart : [];
        $normalized = self::normalize($raw);

        if (self::keysContainDots($raw)) {
            session([$key => $normalized]);
        }

        return $normalized;
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
        $lineKey = self::safeLineKey($lineKey);
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

            $cart[self::newLineKey()] = $item;
        }

        return $cart;
    }

    /**
     * Ensure cart lines use flat string keys (no "."), not a bare numeric list.
     *
     * @param  array<string|int, mixed>  $cart
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $cart): array
    {
        $normalized = [];
        $keyMap = [];

        foreach ($cart as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $originalKey = is_string($key) && $key !== '' ? $key : self::newLineKey();
            $lineKey = self::safeLineKey($originalKey);

            while (isset($normalized[$lineKey])) {
                $lineKey = self::newLineKey();
            }

            if ($originalKey !== $lineKey) {
                $keyMap[$originalKey] = $lineKey;
            }

            $normalized[$lineKey] = $item;
        }

        if ($keyMap !== []) {
            foreach ($normalized as &$item) {
                $linked = $item['linked_domain_cart_key'] ?? null;
                if (is_string($linked) && isset($keyMap[$linked])) {
                    $item['linked_domain_cart_key'] = $keyMap[$linked];
                }
            }
            unset($item);
        }

        return $normalized;
    }

    public static function safeLineKey(?string $key = null): string
    {
        if ($key === null || $key === '') {
            return self::newLineKey();
        }

        if (! str_contains($key, '.')) {
            return $key;
        }

        $safe = str_replace('.', '', $key);

        return $safe !== '' ? $safe : self::newLineKey();
    }

    /**
     * @param  array<string|int, mixed>  $cart
     */
    private static function keysContainDots(array $cart): bool
    {
        foreach (array_keys($cart) as $key) {
            if (is_string($key) && str_contains($key, '.')) {
                return true;
            }
        }

        return false;
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
