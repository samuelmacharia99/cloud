<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class PlatformApiTokenService
{
    public const TOKEN_NAME = 'platform-website-api';

    public const TOKEN_ABILITY = 'platform-public-api';

    public function hasActiveToken(): bool
    {
        return PersonalAccessToken::query()
            ->where('name', self::TOKEN_NAME)
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('is_admin', true)->pluck('id'))
            ->exists();
    }

    /**
     * @return array{exists: bool, created_at: string|null, last_used_at: string|null, hint: string|null, admin_name: string|null}
     */
    public function metadata(): array
    {
        $token = PersonalAccessToken::query()
            ->where('name', self::TOKEN_NAME)
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('is_admin', true)->pluck('id'))
            ->latest('id')
            ->first();

        $hint = Setting::getValue('public_website_api_token_hint');

        if (! $token) {
            return [
                'exists' => false,
                'created_at' => null,
                'last_used_at' => null,
                'hint' => null,
                'admin_name' => null,
            ];
        }

        $admin = User::find($token->tokenable_id);

        return [
            'exists' => true,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'hint' => $hint,
            'admin_name' => $admin?->name,
        ];
    }

    public function regenerate(User $admin): string
    {
        if (! $admin->is_admin) {
            throw new \InvalidArgumentException('Only administrators can manage the platform API token.');
        }

        PersonalAccessToken::query()
            ->where('name', self::TOKEN_NAME)
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('is_admin', true)->pluck('id'))
            ->delete();

        $plainText = $admin->createToken(self::TOKEN_NAME, [self::TOKEN_ABILITY])->plainTextToken;

        Setting::setValue('public_website_api_token_hint', Str::substr($plainText, -4));
        Setting::setValue('public_website_api_token_admin_id', (string) $admin->id);

        return $plainText;
    }
}
