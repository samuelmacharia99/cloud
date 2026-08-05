<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\ServerProductConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        // Type filter
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $products = $query->withCount('services')->paginate(15)->withQueryString();

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('admin.products.index', compact('products', 'currency', 'currencyCode'));
    }

    public function create()
    {
        return view('admin.products.create');
    }

    public function store(Request $request)
    {
        $type = $request->input('type');
        $validated = $request->validate($this->validationRules($type));

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated = $this->normalizeValidatedProductData($validated, $type);

        Product::create($validated);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product)
    {
        $product->load('services.user');

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('admin.products.show', compact('product', 'currency', 'currencyCode'));
    }

    public function edit(Product $product)
    {
        return view('admin.products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $type = $product->type;
        $validated = $request->validate($this->validationRules($type, $product));

        $validated = $this->normalizeValidatedProductData($validated, $type);

        $product->update($validated);

        $message = 'Product updated successfully.';
        if ($type === 'email_hosting') {
            $sync = app(MailcowProvisioningService::class)->syncSendLimitsForProduct($product->fresh());
            if ($sync['updated'] > 0 || $sync['failed'] > 0) {
                $message .= ' Daily send limit synced to '.$sync['updated'].' mailbox domain(s)';
                if ($sync['failed'] > 0) {
                    $message .= ' ('.$sync['failed'].' failed)';
                }
                $message .= '.';
            }
        }

        return redirect()->route('admin.products.show', $product)
            ->with('success', $message);
    }

    public function destroy(Product $product)
    {
        if (! $product->canBeDeleted()) {
            return redirect()->route('admin.products.index')
                ->with('error', $product->deletionBlockedMessage());
        }

        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', 'Product deleted successfully.');
    }

    public function duplicate(Product $product)
    {
        abort_if($product->type !== 'container_hosting', 404);

        $copy = $product->replicate();
        $copy->name = $this->uniqueCopyName($product->name);
        $copy->slug = $this->uniqueCopySlug($product->slug);
        $copy->is_active = false;
        $copy->featured = false;
        $copy->save();

        return redirect()
            ->route('admin.products.edit', $copy)
            ->with('success', 'Product duplicated. Review settings and activate when ready.');
    }

    public function toggleActive(Product $product)
    {
        $product->is_active = ! $product->is_active;
        $product->save();

        $label = $product->is_active ? 'activated' : 'deactivated';

        return redirect()
            ->back()
            ->with('success', "{$product->name} {$label}. Existing services keep billing as usual.");
    }

    private function validationRules(string $type, ?Product $product = null): array
    {
        $productId = $product?->id;

        $rules = [
            'name' => 'required|string|max:255|unique:products,name'.($productId ? ','.$productId : ''),
            'slug' => ($productId ? 'required' : 'nullable').'|string|max:255|unique:products,slug'.($productId ? ','.$productId : ''),
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'type' => 'required|in:'.implode(',', array_keys(Product::TYPES)),
            'container_template_id' => 'nullable|exists:container_templates,id',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'provisioning_driver_key' => 'nullable|string',
            'is_active' => 'boolean',
            'visible_to_resellers' => 'boolean',
            'featured' => 'boolean',
            'overage_enabled' => 'boolean',
            'cpu_overage_rate' => 'nullable|numeric|min:0',
            'ram_overage_rate' => 'nullable|numeric|min:0',
            'disk_overage_rate' => 'nullable|numeric|min:0',
            'bandwidth_overage_enabled' => 'boolean',
            'bandwidth_overage_rate' => 'nullable|numeric|min:0',
        ];

        if ($type === 'shared_hosting') {
            $rules['direct_admin_package_id'] = 'required|exists:direct_admin_packages,id';
            $rules['resource_limits'] = 'nullable';
            $rules['wholesale_monthly_price'] = 'nullable';
            $rules['wholesale_yearly_price'] = 'nullable';

            return $rules;
        }

        $rules['wholesale_monthly_price'] = 'nullable|numeric|min:0';
        $rules['wholesale_yearly_price'] = 'nullable|numeric|min:0';
        $rules['direct_admin_package_id'] = 'nullable';

        if ($type === 'container_hosting') {
            $rules['resource_limits'] = 'nullable|array';
            $rules['resource_limits.cpu'] = 'nullable|numeric|min:0';
            $rules['resource_limits.memory'] = 'nullable|integer|min:0';
            $rules['resource_limits.disk'] = 'nullable|numeric|min:0';
            $rules['resource_limits.bandwidth_gb'] = 'nullable|numeric|min:0';
            $rules['bundled_email_product_id'] = [
                'nullable',
                'exists:products,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value) {
                        return;
                    }
                    $email = Product::query()->find($value);
                    if (! $email || $email->type !== 'email_hosting') {
                        $fail('Select a valid Email Hosting product to bundle.');
                    }
                },
            ];
            $rules['bundle_email_include_in_invoice'] = 'sometimes|boolean';
            $rules['bundle_email_billing_cycle'] = 'nullable|in:monthly,annual';
            $rules['bundle_email_billing_delay_months'] = 'nullable|integer|min:0|max:36';

            return $rules;
        }

        if ($type === 'email_hosting') {
            $rules['resource_limits'] = 'nullable|array';
            $rules['resource_limits.mailboxes'] = 'nullable|integer|min:1|max:10000';
            $rules['resource_limits.aliases'] = 'nullable|integer|min:0|max:10000';
            $rules['resource_limits.msgs_per_day'] = 'nullable|integer|min:1|max:1000000';
            $rules['resource_limits.quota_gb'] = 'nullable|numeric|min:0.1|max:10240';
            $rules['resource_limits.mailbox_quota_gb'] = 'nullable|numeric|min:0.1|max:10240';
            $rules['resource_limits.quota_mb'] = 'nullable|integer|min:100';
            $rules['resource_limits.mailbox_quota_mb'] = 'nullable|integer|min:100';

            return $rules;
        }

        if (in_array($type, ['vps', 'dedicated_server'], true)) {
            $rules['resource_limits'] = 'nullable|array';
            $rules['resource_limits.cpu_cores'] = 'nullable|integer|min:1';
            $rules['resource_limits.ram_gb'] = 'nullable|integer|min:1';
            $rules['resource_limits.storage_gb'] = 'nullable|integer|min:1';
            $rules['resource_limits.storage_type'] = 'nullable|string|max:50';
            $rules['resource_limits.raid'] = 'nullable|string|max:100';
            $rules['resource_limits.bandwidth_tb'] = 'nullable|numeric|min:0';
            $rules['resource_limits.additional_ip_monthly'] = 'nullable|numeric|min:0';
            $rules['resource_limits.additional_ip_setup'] = 'nullable|numeric|min:0';
            $rules['resource_limits.money_back_days'] = 'nullable|integer|min:0';
            $rules['resource_limits.managed'] = 'nullable|boolean';
            $rules['resource_limits.locations'] = 'nullable|array';
            $rules['resource_limits.locations.*.name'] = 'required_with:resource_limits.locations|string|max:255';
            $rules['resource_limits.locations.*.key'] = 'nullable|string|max:100';
            $rules['resource_limits.locations.*.city'] = 'nullable|string|max:255';
            $rules['resource_limits.locations.*.monthly_surcharge'] = 'nullable|numeric|min:0';
            $rules['resource_limits.locations.*.yearly_surcharge'] = 'nullable|numeric|min:0';
            $rules['resource_limits.locations.*.wholesale_monthly_surcharge'] = 'nullable|numeric|min:0';
            $rules['resource_limits.locations.*.wholesale_yearly_surcharge'] = 'nullable|numeric|min:0';
            $rules['resource_limits.locations.*.setup_surcharge'] = 'nullable|numeric|min:0';

            return $rules;
        }

        $resourceLimits = request()->input('resource_limits');
        $rules['resource_limits'] = ($resourceLimits !== null && ! is_array($resourceLimits))
            ? 'nullable|json'
            : 'nullable';

        return $rules;
    }

    private function normalizeValidatedProductData(array $validated, string $type): array
    {
        if (is_string($validated['resource_limits'] ?? null)) {
            $validated['resource_limits'] = json_decode($validated['resource_limits'], true);
        }

        // Empty optional number inputs arrive as null and break NOT NULL columns.
        if (! array_key_exists('setup_fee', $validated) || $validated['setup_fee'] === null || $validated['setup_fee'] === '') {
            $validated['setup_fee'] = 0;
        }

        if ($type === 'shared_hosting') {
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['resource_limits'] = null;
            $validated['bundled_email_product_id'] = null;
            $validated['bundle_email_include_in_invoice'] = false;
            $validated['bundle_email_billing_cycle'] = null;
            $validated['bundle_email_billing_delay_months'] = 0;

            return $validated;
        }

        if ($type === 'container_hosting') {
            $validated['setup_fee'] = 0;
            $validated['provisioning_driver_key'] = null;
            $validated['wholesale_monthly_price'] = null;
            $validated['wholesale_yearly_price'] = null;
            $validated['direct_admin_package_id'] = null;
            $validated['container_template_id'] = filled($validated['container_template_id'] ?? null)
                ? (int) $validated['container_template_id']
                : null;
            $validated['resource_limits'] = $this->normalizeContainerResourceLimits($validated['resource_limits'] ?? null);
            $validated['overage_enabled'] = filter_var($validated['overage_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $validated['bandwidth_overage_enabled'] = filter_var(
                $validated['bandwidth_overage_enabled'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $validated['bundled_email_product_id'] = filled($validated['bundled_email_product_id'] ?? null)
                ? (int) $validated['bundled_email_product_id']
                : null;
            $validated['bundle_email_include_in_invoice'] = filter_var(
                $validated['bundle_email_include_in_invoice'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $validated['bundle_email_billing_cycle'] = filled($validated['bundle_email_billing_cycle'] ?? null)
                ? $validated['bundle_email_billing_cycle']
                : null;
            $validated['bundle_email_billing_delay_months'] = max(0, (int) ($validated['bundle_email_billing_delay_months'] ?? 0));

            if ($validated['bundled_email_product_id'] === null) {
                $validated['bundle_email_include_in_invoice'] = false;
                $validated['bundle_email_billing_cycle'] = null;
                $validated['bundle_email_billing_delay_months'] = 0;
            }

            return $validated;
        }

        // Non-container products clear bundle fields.
        $validated['bundled_email_product_id'] = null;
        $validated['bundle_email_include_in_invoice'] = false;
        $validated['bundle_email_billing_cycle'] = null;
        $validated['bundle_email_billing_delay_months'] = 0;

        if ($type === 'email_hosting') {
            $validated['provisioning_driver_key'] = $validated['provisioning_driver_key'] ?: 'mailcow';
            $validated['container_template_id'] = null;
            $validated['direct_admin_package_id'] = null;
            $validated['resource_limits'] = $this->normalizeEmailHostingResourceLimits($validated['resource_limits'] ?? null);

            return $validated;
        }

        if (in_array($type, ['vps', 'dedicated_server'], true)) {
            $validated['direct_admin_package_id'] = null;
            $validated['provisioning_driver_key'] = null;

            $service = app(ServerProductConfigService::class);
            $validated['resource_limits'] = $service->normalizeFromRequest($validated['resource_limits'] ?? [], $type);

            return $validated;
        }

        $validated['direct_admin_package_id'] = null;

        return $validated;
    }

    /**
     * @param  array<string, mixed>|null  $limits
     * @return array{mailboxes: int, aliases: int, quota_mb: int, mailbox_quota_mb: int, msgs_per_day: int}
     */
    private function normalizeEmailHostingResourceLimits(?array $limits): array
    {
        $limits = is_array($limits) ? $limits : [];

        $quotaMb = $limits['quota_mb'] ?? null;
        if (($quotaMb === null || $quotaMb === '') && isset($limits['quota_gb']) && $limits['quota_gb'] !== '' && $limits['quota_gb'] !== null) {
            $quotaMb = (float) $limits['quota_gb'] * 1024;
        }

        $mailboxQuotaMb = $limits['mailbox_quota_mb'] ?? null;
        if (($mailboxQuotaMb === null || $mailboxQuotaMb === '') && isset($limits['mailbox_quota_gb']) && $limits['mailbox_quota_gb'] !== '' && $limits['mailbox_quota_gb'] !== null) {
            $mailboxQuotaMb = (float) $limits['mailbox_quota_gb'] * 1024;
        }

        return [
            'mailboxes' => max(1, (int) ($limits['mailboxes'] ?? config('mailcow.default_mailboxes', 10))),
            'aliases' => max(0, (int) ($limits['aliases'] ?? config('mailcow.default_aliases', 20))),
            'quota_mb' => max(100, (int) round((float) ($quotaMb ?? config('mailcow.default_quota_mb', 51200)))),
            'mailbox_quota_mb' => max(100, (int) round((float) ($mailboxQuotaMb ?? config('mailcow.default_mailbox_quota_mb', 5120)))),
            'msgs_per_day' => max(1, (int) ($limits['msgs_per_day'] ?? config('mailcow.default_msgs_per_day', 500))),
        ];
    }

    private function normalizeContainerResourceLimits(?array $limits): ?array
    {
        if (! is_array($limits)) {
            return null;
        }

        $normalized = [];

        if (array_key_exists('cpu', $limits) && $limits['cpu'] !== '' && $limits['cpu'] !== null) {
            $normalized['cpu'] = (float) $limits['cpu'];
        }

        if (array_key_exists('memory', $limits) && $limits['memory'] !== '' && $limits['memory'] !== null) {
            $normalized['memory'] = (int) $limits['memory'];
        }

        if (array_key_exists('disk', $limits) && $limits['disk'] !== '' && $limits['disk'] !== null) {
            $normalized['disk'] = (float) $limits['disk'];
        }

        if (array_key_exists('bandwidth_gb', $limits) && $limits['bandwidth_gb'] !== '' && $limits['bandwidth_gb'] !== null) {
            $normalized['bandwidth_gb'] = (float) $limits['bandwidth_gb'];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function uniqueCopyName(string $name): string
    {
        $base = preg_replace('/ \(Copy(?: \d+)?\)$/', '', $name) ?: $name;
        $candidate = $base.' (Copy)';
        $suffix = 2;

        while (Product::where('name', $candidate)->exists()) {
            $candidate = $base.' (Copy '.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueCopySlug(string $slug): string
    {
        $base = preg_replace('/-copy(?:-\d+)?$/', '', $slug) ?: $slug;
        $candidate = $base.'-copy';
        $suffix = 2;

        while (Product::where('slug', $candidate)->exists()) {
            $candidate = $base.'-copy-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
