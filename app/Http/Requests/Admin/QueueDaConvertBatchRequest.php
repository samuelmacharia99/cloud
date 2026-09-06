<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueueDaConvertBatchRequest extends FormRequest
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
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
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
            'confirm_silent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_ids.required' => 'Select at least one DirectAdmin account to convert.',
            'product_id.required' => 'Select the Application Hosting plan these accounts will renew on.',
            'confirm_silent.accepted' => 'Confirm this silent batch convert before queueing it.',
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
