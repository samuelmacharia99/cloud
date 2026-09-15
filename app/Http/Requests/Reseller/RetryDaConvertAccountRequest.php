<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Validation\Rule;

class RetryDaConvertAccountRequest extends QueueDaConvertBatchRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $resellerId = (int) $this->user()?->id;

        return [
            'account_key' => ['required', 'string', 'max:120', 'regex:/^(service:\\d+|da:[A-Za-z0-9._-]+)$/'],
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_key.required' => 'Choose the account to retry.',
        ];
    }
}
