<?php

namespace App\Services\Provisioning;

use App\Models\DirectAdminPackage;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;

class DirectAdminSetupService
{
    public function __construct(
        private DirectAdminCredentialService $credentials,
        private DirectAdminDomainValidator $domainValidator,
    ) {}

    /**
     * @return array{node_id: int, meta: array<string, mixed>}
     */
    public function prepareForOrder(Product $product, User $user, string $domainFqdn): array
    {
        $domain = $this->domainValidator->assertValid($domainFqdn);

        $package = $product->directAdminPackage;
        if (! $package) {
            throw new \RuntimeException("Product \"{$product->name}\" has no DirectAdmin package assigned.");
        }

        $node = $package->node;
        if (! $node || $node->type !== 'directadmin') {
            throw new \RuntimeException("DirectAdmin package \"{$package->name}\" is not linked to an active DirectAdmin node.");
        }

        if (! $node->is_active) {
            throw new \RuntimeException("DirectAdmin node \"{$node->name}\" is not active.");
        }

        return [
            'node_id' => $node->id,
            'meta' => [
                'username' => $this->credentials->generateUsername($user),
                'password' => $this->credentials->generatePassword(),
                'domain' => $domain,
                'package' => $package->package_key,
                'package_name' => $package->name,
                'node_id' => $node->id,
                'node_name' => $node->name,
            ],
        ];
    }

    public function resolvePackageName(Service $service): string
    {
        $meta = $service->service_meta ?? [];

        if (! empty($meta['package_name'])) {
            return (string) $meta['package_name'];
        }

        $productPackage = $service->product?->directAdminPackage;
        if ($productPackage?->name) {
            return $productPackage->name;
        }

        if (! empty($meta['package'])) {
            $packageKey = (string) $meta['package'];
            $nodeId = $service->node_id ?? ($meta['node_id'] ?? null);

            if ($nodeId) {
                $matched = DirectAdminPackage::where('node_id', $nodeId)
                    ->where('package_key', $packageKey)
                    ->value('name');

                if ($matched) {
                    return $matched;
                }
            }
        }

        return Setting::getValue('directadmin_default_package', 'default');
    }

    public function resolveCredentials(Service $service): array
    {
        $meta = $service->service_meta ?? [];

        $username = $meta['username'] ?? $this->credentials->generateUsernameFromService($service);
        $password = $meta['password'] ?? $this->credentials->generatePassword();

        if (empty($meta['domain'])) {
            throw new \RuntimeException('Shared hosting service is missing a primary domain.');
        }

        $domain = $this->domainValidator->assertValid((string) $meta['domain']);

        return compact('username', 'password', 'domain');
    }
}
