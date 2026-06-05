<?php

namespace App\Services\Hosting;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CustomerHostingPanelService
{
    public function assertManageable(Service $service): void
    {
        if (! $service->isSharedHosting()) {
            throw new RuntimeException('This service does not include a hosting control panel.');
        }

        if ($service->status !== ServiceStatus::Active) {
            throw new RuntimeException('Hosting panel is only available for active services.');
        }

        if (! $service->node || $service->node->type !== 'directadmin') {
            throw new RuntimeException('This hosting service is not linked to a DirectAdmin server.');
        }

        if (! $this->resolveUsername($service)) {
            throw new RuntimeException('Hosting account username is not available yet.');
        }
    }

    public function api(Service $service): DirectAdminCustomerPanelApi
    {
        $this->assertManageable($service);

        return DirectAdminCustomerPanelApi::forServiceNode($service->node);
    }

    public function domain(Service $service): string
    {
        $meta = $service->service_meta ?? [];
        $domain = $meta['domain'] ?? null;

        if (is_string($domain) && $domain !== '') {
            return strtolower($domain);
        }

        $credentials = $service->getHostingCredentials();

        return strtolower((string) ($credentials['domain'] ?? ''));
    }

    public function username(Service $service): string
    {
        $username = $this->resolveUsername($service);
        if (! $username) {
            throw new RuntimeException('Hosting account username is not available.');
        }

        return $username;
    }

    /**
     * @return array{success: bool, url?: string, message?: string}
     */
    public function createPanelLoginUrl(Service $service): array
    {
        $api = $this->api($service);

        if (! $api->isAvailable()) {
            return ['success' => false, 'message' => 'DirectAdmin API is not configured on this server.'];
        }

        return $api->createOneTimeLoginUrl($this->username($service));
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Service $service): array
    {
        $cacheKey = "hosting_panel:dashboard:{$service->id}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($service) {
            $result = $this->api($service)->getDashboard(
                $this->username($service),
                $this->domain($service) ?: null,
            );

            if (! $result['success']) {
                throw new RuntimeException($result['message']);
            }

            return $result['data'];
        });
    }

    public function flushDashboardCache(Service $service): void
    {
        Cache::forget("hosting_panel:dashboard:{$service->id}");
    }

    public function syncHostingPassword(Service $service, string $password): void
    {
        $meta = $service->service_meta ?? [];
        $meta['password'] = $password;
        $service->update(['service_meta' => $meta]);

        $credentials = $service->credentials ? json_decode($service->credentials, true) : [];
        if (is_array($credentials)) {
            $credentials['password'] = $password;
            $service->update(['credentials' => json_encode($credentials)]);
        }
    }

    public function generatePassword(): string
    {
        return Str::password(16, symbols: true);
    }

    /**
     * @return array{success: bool, message: string, password?: string}
     */
    public function resetHostingPassword(Service $service): array
    {
        $password = $this->generatePassword();
        $result = $this->api($service)->updatePassword($this->username($service), $password);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        $this->syncHostingPassword($service, $password);
        $this->flushDashboardCache($service);

        Log::info('Customer hosting password reset via panel', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
        ]);

        return [
            'success' => true,
            'message' => 'Hosting password updated successfully.',
            'password' => $password,
        ];
    }

    private function resolveUsername(Service $service): ?string
    {
        return $service->external_reference
            ?? ($service->service_meta['username'] ?? null);
    }
}
