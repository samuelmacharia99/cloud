<?php

namespace App\Http\Requests\Reseller;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\Provisioning\DaResellerPackageImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reseller queues converts of their own DirectAdmin accounts onto their
 * own Application Hosting plans. Every plan id is checked against the
 * reseller's catalogue, never against the platform's products.
 */
class QueueDaConvertBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_reseller ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $resellerId = (int) $this->user()?->id;

        return [
            'account_keys' => ['required', 'array', 'min:1'],
            'account_keys.*' => ['string', 'max:120', 'regex:/^(service:\\d+|da:[A-Za-z0-9._-]+)$/'],
            'plans' => ['nullable', 'array'],
            'plans.*' => [
                'nullable',
                'integer',
                Rule::exists('reseller_products', 'id')->where('reseller_id', $resellerId)->where('type', 'container_hosting'),
            ],
            'reseller_product_id' => [
                'nullable',
                'integer',
                Rule::exists('reseller_products', 'id')->where('reseller_id', $resellerId)->where('type', 'container_hosting'),
            ],
            'email_reseller_product_id' => [
                'nullable',
                'integer',
                Rule::exists('reseller_products', 'id')->where('reseller_id', $resellerId)->where('type', 'email_hosting'),
            ],
            'acknowledge_mail_pull' => ['nullable', 'boolean'],
            'acknowledge_addon_sites' => ['nullable', 'boolean'],
            'confirm_silent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_keys.required' => 'Select at least one DirectAdmin account to move.',
            'plans.*.exists' => 'Pick a plan from your own Application Hosting plans.',
            'reseller_product_id.exists' => 'Pick a fallback plan from your own Application Hosting plans.',
            'confirm_silent.accepted' => 'Confirm that your customers are not emailed by the platform during the move.',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function planChoices(): array
    {
        $plans = [];
        foreach ((array) ($this->validated('plans') ?? []) as $key => $id) {
            if ((int) $id > 0 && preg_match('/^(service:\d+|da:[A-Za-z0-9._-]+)$/', (string) $key)) {
                $plans[(string) $key] = (int) $id;
            }
        }

        return $plans;
    }

    public function fallbackEngine(): ?Product
    {
        $id = (int) ($this->validated('reseller_product_id') ?? 0);
        if ($id <= 0) {
            return null;
        }
        $listing = ResellerProduct::query()->where('reseller_id', $this->user()->id)->find($id);

        return $listing ? app(DaResellerPackageImportService::class)->engineForListing($listing, null) : null;
    }

    public function fallbackListing(): ?ResellerProduct
    {
        $id = (int) ($this->validated('reseller_product_id') ?? 0);

        return $id > 0 ? ResellerProduct::query()->where('reseller_id', $this->user()->id)->find($id) : null;
    }

    /**
     * The reseller's own email plan behind the mail pull, or null for the
     * platform default. Only a Mailcow-backed listing qualifies.
     */
    public function emailHostingProduct(): ?Product
    {
        $id = (int) ($this->validated('email_reseller_product_id') ?? 0);
        if ($id <= 0) {
            return null;
        }
        $listing = ResellerProduct::query()->where('reseller_id', $this->user()->id)->with('adminProduct')->find($id);
        $product = $listing?->adminProduct;

        return $product && $product->type === 'email_hosting' && $product->provisioning_driver_key === 'mailcow' && $product->is_active
            ? $product
            : null;
    }
}
