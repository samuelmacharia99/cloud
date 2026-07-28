<?php

namespace App\Services;

use App\Models\User;

/**
 * Minimal storefront promo codes stored on reseller branding settings.
 * Not a full coupon engine — one active code per reseller (percent or fixed).
 */
class ResellerStorefrontPromoService
{
    public const SESSION_KEY = 'storefront_promo_code';

    /**
     * @return array{code: string, type: string, value: float}|null
     */
    public function configuredPromo(User $reseller): ?array
    {
        $branding = $reseller->settings['branding'] ?? [];
        $code = strtoupper(trim((string) ($branding['promo_code'] ?? '')));
        $type = (string) ($branding['promo_type'] ?? 'percent');
        $value = (float) ($branding['promo_value'] ?? 0);

        if ($code === '' || $value <= 0) {
            return null;
        }

        if (! in_array($type, ['percent', 'fixed'], true)) {
            $type = 'percent';
        }

        if ($type === 'percent' && $value > 100) {
            $value = 100;
        }

        return [
            'code' => $code,
            'type' => $type,
            'value' => $value,
        ];
    }

    public function matches(User $reseller, ?string $code): bool
    {
        $promo = $this->configuredPromo($reseller);
        if (! $promo || ! filled($code)) {
            return false;
        }

        return strtoupper(trim($code)) === $promo['code'];
    }

    /**
     * @return array{
     *     code: string|null,
     *     discount: float,
     *     label: string|null,
     *     applied: bool
     * }
     */
    public function resolve(User $reseller, float $subtotal, ?string $sessionCode = null): array
    {
        $code = $sessionCode ?? session(self::SESSION_KEY);
        $promo = $this->configuredPromo($reseller);

        if (! $promo || ! $this->matches($reseller, is_string($code) ? $code : null)) {
            return [
                'code' => null,
                'discount' => 0.0,
                'label' => null,
                'applied' => false,
            ];
        }

        $discount = $promo['type'] === 'fixed'
            ? min($subtotal, $promo['value'])
            : round($subtotal * ($promo['value'] / 100), 2);

        $label = $promo['type'] === 'fixed'
            ? $promo['code'].' (−KES '.number_format($promo['value'], 0).')'
            : $promo['code'].' (−'.rtrim(rtrim(number_format($promo['value'], 2), '0'), '.').'%)';

        return [
            'code' => $promo['code'],
            'discount' => max(0.0, $discount),
            'label' => $label,
            'applied' => $discount > 0,
        ];
    }

    public function remember(?string $code): void
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '') {
            session()->forget(self::SESSION_KEY);

            return;
        }

        session([self::SESSION_KEY => $normalized]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
