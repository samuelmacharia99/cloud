<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

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
     * @return array{exists: bool, created_at: string|null, last_used_at: string|null, hint: string|null, copyable: bool}
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
                'copyable' => false,
            ];
        }

        return [
            'exists' => true,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'hint' => $hint,
            'copyable' => $this->hasEncryptedPlainText($reseller),
        ];
    }

    public function revealPlainText(User $reseller): ?string
    {
        $encrypted = $reseller->settings['public_api']['token_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasEncryptedPlainText(User $reseller): bool
    {
        $encrypted = $reseller->settings['public_api']['token_encrypted'] ?? null;

        return is_string($encrypted) && $encrypted !== '';
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
            'token_encrypted' => Crypt::encryptString($plainText),
            'token_regenerated_at' => now()->toIso8601String(),
        ]);

        $reseller->update(['settings' => $settings]);

        return $plainText;
    }
}
