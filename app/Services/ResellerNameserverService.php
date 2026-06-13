<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResellerNameserverService
{
    private const HOSTNAME_RULE = 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i';

    public function __construct(
        private NodeNameserverService $nodeNameserver,
    ) {}

    /**
     * @return array{use_platform_defaults: bool, ns1: string, ns2: string, ns3: string, ns4: string}
     */
    public function getSettings(User $reseller): array
    {
        $stored = $reseller->settings['nameservers'] ?? [];

        return array_merge([
            'use_platform_defaults' => true,
            'ns1' => '',
            'ns2' => '',
            'ns3' => '',
            'ns4' => '',
        ], $stored);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(User $reseller, array $data): void
    {
        $usePlatformDefaults = (bool) ($data['use_platform_defaults'] ?? true);

        $settings = $reseller->settings ?? [];
        $settings['nameservers'] = [
            'use_platform_defaults' => $usePlatformDefaults,
            'ns1' => $usePlatformDefaults ? '' : trim((string) ($data['ns1'] ?? '')),
            'ns2' => $usePlatformDefaults ? '' : trim((string) ($data['ns2'] ?? '')),
            'ns3' => $usePlatformDefaults ? '' : trim((string) ($data['ns3'] ?? '')),
            'ns4' => $usePlatformDefaults ? '' : trim((string) ($data['ns4'] ?? '')),
            'updated_at' => now()->toIso8601String(),
        ];

        $reseller->update(['settings' => $settings]);
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function platformDefaults(): array
    {
        return $this->nodeNameserver->platformDefaults();
    }

    /**
     * Default nameservers applied to new reseller domain cart items.
     *
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function defaultsForReseller(User $reseller): array
    {
        $settings = $this->getSettings($reseller);

        if ($settings['use_platform_defaults']) {
            return $this->platformDefaults();
        }

        return $this->normalizeNs(
            $settings['ns1'],
            $settings['ns2'] ?: null,
            $settings['ns3'] ?: null,
            $settings['ns4'] ?: null,
        );
    }

    /**
     * Initial nameserver payload stored on cart items.
     *
     * @return array{use_default: bool, ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function cartDefaultPayload(User $reseller): array
    {
        $settings = $this->getSettings($reseller);
        $defaults = $this->defaultsForReseller($reseller);

        return [
            'use_default' => $settings['use_platform_defaults'],
            ...$defaults,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function resolveForItem(User $reseller, array $item): array
    {
        $stored = $item['nameservers'] ?? null;

        if (! is_array($stored)) {
            return $this->defaultsForReseller($reseller);
        }

        if (! empty($stored['use_default'])) {
            return $this->defaultsForReseller($reseller);
        }

        return $this->normalizeNs(
            (string) ($stored['ns1'] ?? ''),
            $stored['ns2'] ?? null,
            $stored['ns3'] ?? null,
            $stored['ns4'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{nameserver_1: string, nameserver_2: ?string, nameserver_3: ?string, nameserver_4: ?string}
     */
    public function domainColumnsForItem(User $reseller, array $item): array
    {
        return $this->nodeNameserver->toDomainColumns($this->resolveForItem($reseller, $item));
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    public function mergeSubmittedIntoCart(User $reseller, array $cart, array $submitted): array
    {
        foreach ($cart as $key => $item) {
            if (! $this->itemNeedsNameservers($item)) {
                continue;
            }

            if (! isset($submitted[$key])) {
                continue;
            }

            $cart[$key]['nameservers'] = $this->parseSubmitted($submitted[$key], $reseller);
        }

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $cart
     *
     * @throws ValidationException
     */
    public function validateCartNameservers(User $reseller, array $cart): void
    {
        foreach ($cart as $key => $item) {
            if (! $this->itemNeedsNameservers($item)) {
                continue;
            }

            $this->validatePayload($item['nameservers'] ?? [], $reseller, "nameservers.{$key}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{use_default: bool, ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function parseSubmitted(array $data, User $reseller): array
    {
        $this->validatePayload($data, $reseller);

        $useDefault = filter_var($data['use_default'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($useDefault) {
            $defaults = $this->defaultsForReseller($reseller);

            return [
                'use_default' => true,
                ...$defaults,
            ];
        }

        return [
            'use_default' => false,
            'ns1' => strtolower(trim((string) $data['ns1'])),
            'ns2' => ! empty($data['ns2']) ? strtolower(trim((string) $data['ns2'])) : null,
            'ns3' => ! empty($data['ns3']) ? strtolower(trim((string) $data['ns3'])) : null,
            'ns4' => ! empty($data['ns4']) ? strtolower(trim((string) $data['ns4'])) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemNeedsNameservers(array $item): bool
    {
        return in_array($item['type'] ?? 'domain', ['domain', 'domain_transfer'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validatePayload(array $data, User $reseller, ?string $prefix = null): void
    {
        $useDefault = filter_var($data['use_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $field = fn (string $name) => $prefix ? "{$prefix}.{$name}" : $name;

        if ($useDefault) {
            return;
        }

        Validator::make($data, [
            'ns1' => ['required', 'string', 'min:3', 'max:253', self::HOSTNAME_RULE],
            'ns2' => ['nullable', 'string', 'min:3', 'max:253', self::HOSTNAME_RULE],
            'ns3' => ['nullable', 'string', 'min:3', 'max:253', self::HOSTNAME_RULE],
            'ns4' => ['nullable', 'string', 'min:3', 'max:253', self::HOSTNAME_RULE],
        ], [
            'ns1.required' => 'At least one nameserver is required.',
        ])->validate();

        $ns1 = strtolower(trim((string) ($data['ns1'] ?? '')));
        if ($ns1 === '') {
            throw ValidationException::withMessages([
                $field('ns1') => 'At least one nameserver is required.',
            ]);
        }
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    private function normalizeNs(string $ns1, ?string $ns2, ?string $ns3, ?string $ns4): array
    {
        return [
            'ns1' => trim($ns1),
            'ns2' => $ns2 ? trim($ns2) : null,
            'ns3' => $ns3 ? trim($ns3) : null,
            'ns4' => $ns4 ? trim($ns4) : null,
        ];
    }
}
