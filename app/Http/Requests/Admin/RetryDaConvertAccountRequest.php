<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryDaConvertAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_key' => ['required', 'string', 'max:120', 'regex:/^(service:\\d+|da:[A-Za-z0-9._-]+)$/'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('type', 'container_hosting')->where('is_active', true),
            ],
            'email_product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('type', 'email_hosting')->where('is_active', true),
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
            'product_id.required' => 'Select a fallback Application Hosting size first.',
        ];
    }

    public function applicationHostingProduct(): Product
    {
        return Product::with('containerTemplate', 'bundledEmailProduct')->findOrFail((int) $this->validated('product_id'));
    }

    public function emailHostingProduct(): ?Product
    {
        $id = (int) ($this->validated('email_product_id') ?? 0);
        if ($id <= 0) {
            return null;
        }

        return Product::query()->find($id);
    }
}
