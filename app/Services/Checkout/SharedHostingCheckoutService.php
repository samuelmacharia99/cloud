<?php

namespace App\Services\Checkout;

use App\Enums\SharedHostingDomainMode;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DomainInputParser;
use App\Services\DomainTransferService;
use App\Services\NodeNameserverService;
use App\Services\Provisioning\DirectAdminDomainValidator;
use App\Services\Provisioning\DirectAdminSetupService;
use App\Services\ResellerCustomerCatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SharedHostingCheckoutService
{
    public function __construct(
        private DirectAdminSetupService $directAdminSetup,
        private DirectAdminDomainValidator $domainValidator,
        private NodeNameserverService $nameserverService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sharedHostingCartItems(array $cart): array
    {
        $items = [];

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product') {
                continue;
            }

            $product = Product::find($item['product_id'] ?? null);
            if (! $product || $product->type !== 'shared_hosting' || $product->provisioning_driver_key !== 'directadmin') {
                continue;
            }

            $items[] = array_merge($item, [
                'key' => $key,
                'product' => $product,
            ]);
        }

        return $items;
    }

    public function validateCheckoutRequest(Request $request, array $cart): void
    {
        $sharedItems = $this->sharedHostingCartItems($cart);

        if (empty($sharedItems)) {
            return;
        }

        $this->normalizeHostingDomainInputs($request, $sharedItems);

        $rules = [];
        $messages = [
            'hosting_domain_mode.*.required' => 'Choose how you want to connect a domain to your shared hosting plan.',
            'hosting_domain_mode.*.in' => 'Invalid domain option selected for shared hosting.',
            'hosting_domain_added.*.accepted' => 'Check availability and add the domain to your order before placing it.',
        ];

        foreach ($sharedItems as $item) {
            $key = $item['key'];
            $rules["hosting_domain_mode.{$key}"] = ['required', Rule::enum(SharedHostingDomainMode::class)];

            $mode = $request->input("hosting_domain_mode.{$key}");

            if ($mode === SharedHostingDomainMode::Register->value) {
                $rules["hosting_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9-]+$/i'];
                $rules["hosting_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["hosting_domain_years.{$key}"] = ['required', 'integer', 'min:1', 'max:10'];
                $rules["hosting_domain_added.{$key}"] = ['accepted'];
            } elseif ($mode === SharedHostingDomainMode::Existing->value) {
                $rules["hosting_domain_fqdn.{$key}"] = ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'];
            } elseif ($mode === SharedHostingDomainMode::Transfer->value) {
                $rules["hosting_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'];
                $rules["hosting_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["hosting_transfer_epp.{$key}"] = ['required', 'string', 'min:5'];
                $rules["hosting_transfer_registrar.{$key}"] = ['required', 'string', 'min:2'];
                $rules["hosting_transfer_registrar_url.{$key}"] = ['nullable', 'url'];
            }
        }

        $request->validate($rules, $messages);
    }

    /**
     * Estimate extra domain charges added at checkout (registration or transfer fees).
     */
    public function estimateDomainAddonTotal(Request $request, array $cart): float
    {
        $total = 0.0;

        foreach ($this->sharedHostingCartItems($cart) as $item) {
            $addon = $this->resolveDomainAddon($request, $item['key']);
            if ($addon) {
                $total += $addon['amount'];
            }
        }

        return $total;
    }

    /**
     * @return array{amount: float, description: string, mode: string}|null
     */
    public function resolveDomainAddon(Request $request, string $cartKey): ?array
    {
        $mode = SharedHostingDomainMode::tryFrom((string) $request->input("hosting_domain_mode.{$cartKey}"));
        if (! $mode) {
            return null;
        }

        if ($mode === SharedHostingDomainMode::Register) {
            if (! $request->boolean("hosting_domain_added.{$cartKey}")) {
                return null;
            }

            $extension = DomainExtension::where('extension', $request->input("hosting_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $years = (int) $request->input("hosting_domain_years.{$cartKey}", 1);
            $amount = app(ResellerCustomerCatalogService::class)->domainRegistrationPrice(
                $request->user(),
                $extension,
                $years,
            ) ?? 0.0;
            $name = strtolower((string) $request->input("hosting_domain_name.{$cartKey}"));

            return [
                'amount' => $amount,
                'description' => "Domain registration: {$name}{$extension->extension} ({$years} year(s))",
                'mode' => $mode->value,
            ];
        }

        if ($mode === SharedHostingDomainMode::Transfer) {
            $extension = DomainExtension::where('extension', $request->input("hosting_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $name = strtolower((string) $request->input("hosting_domain_name.{$cartKey}"));

            return [
                'amount' => (float) ($extension->transfer_price ?? 0),
                'description' => "Domain transfer: {$name}{$extension->extension}",
                'mode' => $mode->value,
            ];
        }

        return null;
    }

    /**
     * Build DirectAdmin service meta and create any linked domain records / invoice lines.
     *
     * @return array{
     *     node_id: int|null,
     *     service_meta: array<string, mixed>,
     *     invoice_items: array<int, array<string, mixed>>
     * }
     */
    public function buildSharedHostingContext(
        Request $request,
        string $cartKey,
        User $user,
        Product $product,
        Invoice $invoice,
        Order $order,
    ): array {
        $mode = SharedHostingDomainMode::from((string) $request->input("hosting_domain_mode.{$cartKey}"));
        $invoiceItems = [];

        $fqdn = match ($mode) {
            SharedHostingDomainMode::Register => $this->fqdnFromParts(
                (string) $request->input("hosting_domain_name.{$cartKey}"),
                (string) $request->input("hosting_domain_extension.{$cartKey}")
            ),
            SharedHostingDomainMode::Existing => $this->domainValidator->assertValid(
                (string) $request->input("hosting_domain_fqdn.{$cartKey}")
            ),
            SharedHostingDomainMode::Transfer => $this->fqdnFromParts(
                (string) $request->input("hosting_domain_name.{$cartKey}"),
                (string) $request->input("hosting_domain_extension.{$cartKey}")
            ),
        };

        $setup = $this->directAdminSetup->prepareForOrder($product, $user, $fqdn);
        $serviceMeta = $setup['meta'];
        $serviceMeta['hosting_domain_mode'] = $mode->value;
        $nameservers = $this->nameserverService->forNodeId($setup['node_id']);
        $domainNameservers = $this->nameserverService->toDomainColumns($nameservers);

        if ($mode === SharedHostingDomainMode::Register) {
            $years = (int) $request->input("hosting_domain_years.{$cartKey}", 1);
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $pricing = $extension->getRetailPricing($years);
            $amount = $pricing ? (float) $pricing->price : 0.0;

            $domain = Domain::create([
                'user_id' => $user->id,
                'name' => $parts['name'],
                'extension' => $parts['extension'],
                'status' => 'pending',
                ...$domainNameservers,
            ]);

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['domain_registration_years'] = $years;

            if ($amount > 0) {
                $invoiceItems[] = [
                    'description' => "Domain registration: {$fqdn} ({$years} year(s))",
                    'amount' => $amount,
                    'meta' => [
                        'type' => 'domain_registration',
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                    ],
                ];
            }
        } elseif ($mode === SharedHostingDomainMode::Existing) {
            $serviceMeta['nameservers'] = $nameservers;
            $serviceMeta['nameserver_instructions'] = 'Update your domain nameservers at your current registrar to point to our hosting nameservers before your site goes live.';
        } elseif ($mode === SharedHostingDomainMode::Transfer) {
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $transferPrice = (float) ($extension->transfer_price ?? 0);

            $domain = DomainTransferService::createTransferRequest(
                $user,
                $parts['name'],
                $parts['extension'],
                (string) $request->input("hosting_transfer_epp.{$cartKey}"),
                (string) $request->input("hosting_transfer_registrar.{$cartKey}"),
                $request->input("hosting_transfer_registrar_url.{$cartKey}")
            );

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['transfer_pending'] = true;

            if ($transferPrice > 0) {
                $invoiceItems[] = [
                    'description' => "Domain transfer: {$fqdn}",
                    'amount' => $transferPrice,
                    'meta' => [
                        'type' => 'domain_transfer',
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                    ],
                ];
            }
        }

        return [
            'node_id' => $setup['node_id'],
            'service_meta' => $serviceMeta,
            'invoice_items' => $invoiceItems,
        ];
    }

    public function persistExtraInvoiceItems(Invoice $invoice, Order $order, array $invoiceItems): void
    {
        foreach ($invoiceItems as $line) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $line['description'],
                'quantity' => 1,
                'unit_price' => $line['amount'],
                'amount' => $line['amount'],
            ]);
        }
    }

    private function fqdnFromParts(string $name, string $extension): string
    {
        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $parsed = app(DomainInputParser::class)->parse($name, $extension, $allowedExtensions);

        if ($parsed !== null) {
            return $this->domainValidator->assertValid($parsed['name'].$parsed['extension']);
        }

        $name = strtolower(trim($name));
        $extension = str_starts_with($extension, '.') ? $extension : '.'.$extension;

        return $this->domainValidator->assertValid($name.$extension);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sharedItems
     */
    private function normalizeHostingDomainInputs(Request $request, array $sharedItems): void
    {
        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $parser = app(DomainInputParser::class);
        $merge = [];

        foreach ($sharedItems as $item) {
            $key = $item['key'];
            $mode = $request->input("hosting_domain_mode.{$key}");

            if (! in_array($mode, [
                SharedHostingDomainMode::Register->value,
                SharedHostingDomainMode::Transfer->value,
            ], true)) {
                continue;
            }

            $parsed = $parser->parse(
                (string) $request->input("hosting_domain_name.{$key}", ''),
                $request->input("hosting_domain_extension.{$key}"),
                $allowedExtensions,
            );

            if ($parsed !== null) {
                $merge["hosting_domain_name.{$key}"] = $parsed['name'];
                $merge["hosting_domain_extension.{$key}"] = $parsed['extension'];
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
