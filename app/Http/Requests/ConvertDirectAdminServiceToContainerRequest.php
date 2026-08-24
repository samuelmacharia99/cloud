<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertDirectAdminServiceToContainerRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('type', 'container_hosting')->where('is_active', true),
            ],
            'database_name' => ['nullable', 'string', 'max:64'],
            'email_product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('type', 'email_hosting')->where('is_active', true),
            ],
            'acknowledge_extra_mailboxes' => ['nullable', 'boolean'],
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
            'product_id.required' => 'Select the Application Hosting plan that will be billed when the DirectAdmin term ends.',
            'product_id.exists' => 'Select an active Application Hosting product.',
            'confirm_silent.accepted' => 'Confirm this silent convert before queueing it.',
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

    public function acknowledgedMailPull(): bool
    {
        return $this->boolean('acknowledge_mail_pull') || $this->boolean('acknowledge_extra_mailboxes');
    }
}
