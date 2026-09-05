<?php

namespace App\Http\Requests\Customer;

use App\Services\Provisioning\DirectAdminDomainValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEmailHostingDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $validator = app(DirectAdminDomainValidator::class);

        $this->merge([
            'domain' => $validator->normalize((string) $this->input('domain', '')),
            'domain_confirmation' => $validator->normalize((string) $this->input('domain_confirmation', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253'],
            'domain_confirmation' => ['required', 'string', 'max:253'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Enter the new mail domain (for example shop.example.com).',
            'domain_confirmation.required' => 'Type the new domain again to confirm the change.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $domain = (string) $this->input('domain');
            $confirmation = (string) $this->input('domain_confirmation');
            $domainValidator = app(DirectAdminDomainValidator::class);

            if (! $domainValidator->isValid($domain)) {
                $validator->errors()->add('domain', 'Enter a valid domain such as example.com.');

                return;
            }

            if ($domain !== $confirmation) {
                $validator->errors()->add(
                    'domain_confirmation',
                    'Confirmation does not match the new mail domain.'
                );
            }
        });
    }
}
