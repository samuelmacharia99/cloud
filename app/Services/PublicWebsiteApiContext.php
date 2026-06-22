<?php

namespace App\Services;

use App\Models\User;

class PublicWebsiteApiContext
{
    public function isReseller(): bool
    {
        return app()->bound('currentReseller');
    }

    public function isPlatform(): bool
    {
        return app()->bound('platformPublicApi') && ! $this->isReseller();
    }

    public function reseller(): User
    {
        return app('currentReseller');
    }

    public function isEnabled(): bool
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->isEnabled($this->reseller());
        }

        if ($this->isPlatform()) {
            return app(PlatformPublicApiService::class)->isEnabled();
        }

        return false;
    }

    public function checkoutUrl(): string
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->checkoutUrl($this->reseller());
        }

        return app(PlatformPublicApiService::class)->checkoutUrl();
    }

    /**
     * @return array{success: bool, query: string, period_years: int, currency: string, checkout_url: string, results: list<array<string, mixed>>}
     */
    public function searchDomains(string $query, int $periodYears = 1): array
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->searchDomains($this->reseller(), $query, $periodYears);
        }

        return app(PlatformPublicApiService::class)->searchDomains($query, $periodYears);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExtensions(int $periodYears = 1): array
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->listExtensions($this->reseller(), $periodYears);
        }

        return app(PlatformPublicApiService::class)->listExtensions($periodYears);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(): array
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->listServices($this->reseller());
        }

        return app(PlatformPublicApiService::class)->listServices();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function buildCartItems(array $items): array
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->buildCartItems($this->reseller(), $items);
        }

        return app(PlatformPublicApiService::class)->buildCartItems($items);
    }

    public function originAllowed(?string $origin): bool
    {
        if ($this->isReseller()) {
            return app(ResellerPublicApiService::class)->originAllowed($this->reseller(), $origin);
        }

        if ($this->isPlatform()) {
            return app(PlatformPublicApiService::class)->originAllowed($origin);
        }

        return false;
    }
}
