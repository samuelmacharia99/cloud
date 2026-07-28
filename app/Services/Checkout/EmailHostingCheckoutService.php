<?php

namespace App\Services\Checkout;

use App\Enums\SharedHostingDomainMode;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\DomainInputParser;
use App\Services\DomainTransferService;
use App\Services\NodeNameserverService;
use App\Services\Provisioning\DirectAdminDomainValidator;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerNameserverService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Domain selection for Email Hosting checkout (register / existing / transfer / from cart).
 */
class EmailHostingCheckoutService
{
    public function __construct(
        private DirectAdminDomainValidator $domainValidator,
        private NodeNameserverService $nameserverService,
        private SharedHostingCheckoutService $sharedHostingCheckout,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function emailHostingCartItems(array $cart): array
    {
        $items = [];

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product') {
                continue;
            }

            $product = Product::find($item['product_id'] ?? null);
            if (! $product || $product->type !== 'email_hosting') {
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
        $emailItems = $this->emailHostingCartItems($cart);

        if ($emailItems === []) {
            return;
        }

        $this->normalizeDomainInputs($request, $emailItems);
        $this->applyLinkedCartDomainModes($request, $cart, $emailItems);

        $rules = [];
        $messages = [
            'email_domain_mode.*.required' => 'Choose how you want to connect a domain to your email plan.',
            'email_domain_mode.*.in' => 'Invalid domain option selected for email hosting.',
            'email_domain_added.*.accepted' => 'Check availability and add the domain to your order before placing it.',
        ];

        foreach ($emailItems as $item) {
            $key = $item['key'];

            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $key)) {
                $rules["email_domain_mode.{$key}"] = ['required', Rule::in([SharedHostingDomainMode::FromCart->value])];

                continue;
            }

            $rules["email_domain_mode.{$key}"] = ['required', Rule::enum(SharedHostingDomainMode::class)];
            $mode = $request->input("email_domain_mode.{$key}");

            if ($mode === SharedHostingDomainMode::Register->value) {
                $rules["email_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9-]+$/i'];
                $rules["email_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["email_domain_years.{$key}"] = ['required', 'integer', 'min:1', 'max:10'];
                $rules["email_domain_added.{$key}"] = ['accepted'];
            } elseif ($mode === SharedHostingDomainMode::Existing->value) {
                $rules["email_domain_fqdn.{$key}"] = ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'];
            } elseif ($mode === SharedHostingDomainMode::Transfer->value) {
                $rules["email_domain_name.{$key}"] = ['required', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'];
                $rules["email_domain_extension.{$key}"] = [
                    'required',
                    Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
                ];
                $rules["email_transfer_epp.{$key}"] = ['required', 'string', 'min:5'];
                $rules["email_transfer_registrar.{$key}"] = ['required', 'string', 'min:2'];
                $rules["email_transfer_registrar_url.{$key}"] = ['nullable', 'url'];
            }
        }

        $request->validate($rules, $messages);
    }

    public function estimateDomainAddonTotal(Request $request, array $cart): float
    {
        $total = 0.0;

        foreach ($this->emailHostingCartItems($cart) as $item) {
            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $item['key'])) {
                continue;
            }

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
        $mode = SharedHostingDomainMode::tryFrom((string) $request->input("email_domain_mode.{$cartKey}"));
        if (! $mode) {
            return null;
        }

        if ($mode === SharedHostingDomainMode::Register) {
            if (! $request->boolean("email_domain_added.{$cartKey}")) {
                return null;
            }

            $extension = DomainExtension::where('extension', $request->input("email_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $years = (int) $request->input("email_domain_years.{$cartKey}", 1);
            $amount = app(ResellerCustomerCatalogService::class)->domainRegistrationPrice(
                $request->user(),
                $extension,
                $years,
            ) ?? 0.0;
            $name = strtolower((string) $request->input("email_domain_name.{$cartKey}"));

            return [
                'amount' => $amount,
                'description' => "Domain registration: {$name}{$extension->extension} ({$years} year(s))",
                'mode' => $mode->value,
            ];
        }

        if ($mode === SharedHostingDomainMode::Transfer) {
            $extension = DomainExtension::where('extension', $request->input("email_domain_extension.{$cartKey}"))
                ->where('enabled', true)
                ->first();

            if (! $extension) {
                return null;
            }

            $name = strtolower((string) $request->input("email_domain_name.{$cartKey}"));

            return [
                'amount' => (float) ($extension->transfer_price ?? 0),
                'description' => "Domain transfer: {$name}{$extension->extension}",
                'mode' => $mode->value,
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     node_id: int|null,
     *     service_meta: array<string, mixed>,
     *     invoice_items: list<array<string, mixed>>
     * }
     */
    public function buildEmailHostingContext(
        Request $request,
        string $cartKey,
        User $user,
        Product $product,
        Invoice $invoice,
        Order $order,
        array $cart = [],
        array $domainsCreatedByCartKey = [],
    ): array {
        $linkedDomain = $this->sharedHostingCheckout->linkedDomainDetails($cart, $cartKey);
        $mode = $linkedDomain
            ? SharedHostingDomainMode::FromCart
            : SharedHostingDomainMode::from((string) $request->input("email_domain_mode.{$cartKey}"));
        $invoiceItems = [];

        if ($mode === SharedHostingDomainMode::FromCart) {
            if (! $linkedDomain) {
                throw new \RuntimeException('The email plan is not linked to a domain in your cart.');
            }
            $fqdn = $this->domainValidator->assertValid($linkedDomain['fqdn']);
        } else {
            $fqdn = match ($mode) {
                SharedHostingDomainMode::Register => $this->fqdnFromParts(
                    (string) $request->input("email_domain_name.{$cartKey}"),
                    (string) $request->input("email_domain_extension.{$cartKey}")
                ),
                SharedHostingDomainMode::Existing => $this->domainValidator->assertValid(
                    (string) $request->input("email_domain_fqdn.{$cartKey}")
                ),
                SharedHostingDomainMode::Transfer => $this->fqdnFromParts(
                    (string) $request->input("email_domain_name.{$cartKey}"),
                    (string) $request->input("email_domain_extension.{$cartKey}")
                ),
                default => throw new \RuntimeException('Unsupported email domain mode.'),
            };
        }

        $mailNode = app(MailcowProvisioningService::class)->resolveNode();
        $cloudflareAvailable = app(DomainCloudflareDnsService::class)->isAvailableForCustomer($user);
        $nameservers = app(ResellerNameserverService::class)->defaultsForCustomer($user);
        $domainNameservers = $this->nameserverService->toDomainColumns($nameservers);

        $limits = app(MailcowProvisioningService::class)->limitsForProduct($product);
        $serviceMeta = [
            'mailcow_domain' => $fqdn,
            'domain' => $fqdn,
            'email_domain_mode' => $mode->value,
            'mailbox_limit' => $limits['mailboxes'],
            'alias_limit' => $limits['aliases'],
            'mailbox_quota_mb' => $limits['mailbox_quota_mb'],
            'quota_mb' => $limits['quota_mb'],
            'msgs_per_day' => $limits['msgs_per_day'],
        ];

        if ($mode === SharedHostingDomainMode::FromCart) {
            $serviceMeta['linked_domain_cart_key'] = $linkedDomain['cart_key'];
            $serviceMeta['domain_registration_years'] = $linkedDomain['years'];
            $serviceMeta['cloudflare_dns'] = true;

            if (isset($domainsCreatedByCartKey[$linkedDomain['cart_key']])) {
                $serviceMeta['domain_id'] = $domainsCreatedByCartKey[$linkedDomain['cart_key']];
            }
        } elseif ($mode === SharedHostingDomainMode::Register) {
            $years = (int) $request->input("email_domain_years.{$cartKey}", 1);
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $pricing = $extension->getRetailPricing($years);
            $amount = $pricing ? (float) $pricing->price : 0.0;

            $domain = Domain::create([
                'user_id' => $user->id,
                'name' => $parts['name'],
                'extension' => $parts['extension'],
                'status' => 'pending',
                'cloudflare_dns_enabled' => $cloudflareAvailable,
                ...$domainNameservers,
            ]);

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['domain_registration_years'] = $years;
            $serviceMeta['cloudflare_dns'] = $cloudflareAvailable;

            if ($amount > 0) {
                $invoiceItems[] = [
                    'description' => "Domain registration: {$fqdn} ({$years} year(s))",
                    'amount' => $amount,
                    'meta' => [
                        'type' => 'domain_registration',
                        'domain_id' => $domain->id,
                        'fqdn' => $fqdn,
                        'years' => $years,
                    ],
                ];
            }
        } elseif ($mode === SharedHostingDomainMode::Existing) {
            $owned = $this->findOwnedDomain($user, $fqdn);
            if ($owned) {
                $serviceMeta['domain_id'] = $owned->id;
                if ($owned->cloudflare_dns_enabled && filled($owned->cloudflare_zone_id)) {
                    $serviceMeta['cloudflare_dns'] = true;
                }
            } else {
                $serviceMeta['nameservers'] = $nameservers;
                $serviceMeta['nameserver_instructions'] = 'Point this domain’s nameservers to Talksasa (or add the MX/SPF/DKIM/DMARC records from your email console) so mail can be delivered.';
            }
        } elseif ($mode === SharedHostingDomainMode::Transfer) {
            $parts = $this->domainValidator->splitFqdn($fqdn);
            $extension = DomainExtension::where('extension', $parts['extension'])->firstOrFail();
            $transferPrice = (float) ($extension->transfer_price ?? 0);

            $domain = DomainTransferService::createTransferRequest(
                $user,
                $parts['name'],
                $parts['extension'],
                (string) $request->input("email_transfer_epp.{$cartKey}"),
                (string) $request->input("email_transfer_registrar.{$cartKey}"),
                $request->input("email_transfer_registrar_url.{$cartKey}")
            );

            $serviceMeta['domain_id'] = $domain->id;
            $serviceMeta['transfer_pending'] = true;
            $serviceMeta['cloudflare_dns'] = $cloudflareAvailable;

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

        $this->sharedHostingCheckout->persistExtraInvoiceItems($invoice, $order, $invoiceItems);

        return [
            'node_id' => $mailNode?->id,
            'service_meta' => $serviceMeta,
            'invoice_items' => [],
        ];
    }

    private function findOwnedDomain(User $user, string $fqdn): ?Domain
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));

        return Domain::query()
            ->where('user_id', $user->id)
            ->get()
            ->first(fn (Domain $domain) => strtolower($domain->fqdn()) === $fqdn);
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
     * @param  list<array<string, mixed>>  $emailItems
     */
    private function applyLinkedCartDomainModes(Request $request, array $cart, array $emailItems): void
    {
        $merge = [];

        foreach ($emailItems as $item) {
            $key = $item['key'];
            if ($this->sharedHostingCheckout->hasLinkedDomainInCart($cart, $key)) {
                $merge["email_domain_mode.{$key}"] = SharedHostingDomainMode::FromCart->value;

                continue;
            }

            $mailDomain = strtolower(trim((string) ($item['mail_domain'] ?? '')));
            if ($mailDomain !== '') {
                $merge["email_domain_mode.{$key}"] = SharedHostingDomainMode::Existing->value;
                $merge["email_domain_fqdn.{$key}"] = $mailDomain;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $emailItems
     */
    private function normalizeDomainInputs(Request $request, array $emailItems): void
    {
        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $parser = app(DomainInputParser::class);
        $merge = [];

        foreach ($emailItems as $item) {
            $key = $item['key'];
            $mode = $request->input("email_domain_mode.{$key}");

            if (! in_array($mode, [
                SharedHostingDomainMode::Register->value,
                SharedHostingDomainMode::Transfer->value,
            ], true)) {
                continue;
            }

            $name = (string) $request->input("email_domain_name.{$key}", '');
            $extension = (string) $request->input("email_domain_extension.{$key}", '');
            $parsed = $parser->parse($name, $extension, $allowedExtensions);

            if ($parsed !== null) {
                $merge["email_domain_name.{$key}"] = $parsed['name'];
                $merge["email_domain_extension.{$key}"] = $parsed['extension'];
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
