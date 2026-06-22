<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class ResellerApiTokenService
{
    public const TOKEN_NAME = 'website-api';

    public const TOKEN_ABILITY = 'reseller-public-api';

    public function hasActiveToken(User $reseller): bool
    {
        return $reseller->tokens()
            ->where('name', self::TOKEN_NAME)
            ->exists();
    }

    /**
     * @return array{exists: bool, created_at: string|null, last_used_at: string|null, hint: string|null}
     */
    public function metadata(User $reseller): array
    {
        $token = $reseller->tokens()
            ->where('name', self::TOKEN_NAME)
            ->latest('id')
            ->first();

        $hint = $reseller->settings['public_api']['token_hint'] ?? null;

        if (! $token) {
            return [
                'exists' => false,
                'created_at' => null,
                'last_used_at' => null,
                'hint' => null,
            ];
        }

        return [
            'exists' => true,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'hint' => $hint,
        ];
    }

    public function regenerate(User $reseller): string
    {
        $reseller->tokens()
            ->where('name', self::TOKEN_NAME)
            ->delete();

        $plainText = $reseller->createToken(self::TOKEN_NAME, [self::TOKEN_ABILITY])->plainTextToken;

        $settings = $reseller->settings ?? [];
        $settings['public_api'] = array_merge($settings['public_api'] ?? [], [
            'token_hint' => Str::substr($plainText, -4),
            'token_regenerated_at' => now()->toIso8601String(),
        ]);

        $reseller->update(['settings' => $settings]);

        return $plainText;
    }
}
