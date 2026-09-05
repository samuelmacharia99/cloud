<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Models\User;
use App\Services\DomainOwnershipTransferService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransferAdminDomainOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $domain = $this->route('domain');

        return $this->user()?->can('transfer', $domain) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_email' => ['required', 'string', 'max:255'],
            'transfer_services' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_user_id.required' => 'Select the customer who should own this domain.',
            'reason.required' => 'Enter why ownership is changing.',
            'reason.min' => 'Enter a short reason for the ownership change.',
            'confirmation_email.required' => 'Type the new owner\'s email to confirm.',
            'transfer_services.boolean' => 'Choose yes or no for moving the hosting service.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Domain $domain */
            $domain = $this->route('domain');
            $target = User::query()->find($this->integer('target_user_id'));

            if (! $target) {
                $validator->errors()->add('target_user_id', 'Selected customer was not found.');

                return;
            }

            $typed = strtolower(trim((string) $this->input('confirmation_email')));
            $expected = strtolower(trim((string) $target->email));

            if ($typed === '' || $typed !== $expected) {
                $validator->errors()->add(
                    'confirmation_email',
                    'Type '.$target->email.' exactly to confirm the new owner.'
                );
            }

            if ((int) $domain->user_id === (int) $target->id) {
                $validator->errors()->add('target_user_id', 'Domain is already assigned to this customer.');
            }

            $linked = app(DomainOwnershipTransferService::class)->linkedServicesForOwner($domain);
            if ($linked->isNotEmpty() && ! $this->has('transfer_services')) {
                $validator->errors()->add(
                    'transfer_services',
                    'Choose whether to move the hosting service with this domain.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
            'confirmation_email' => trim((string) $this->input('confirmation_email')),
        ]);
    }
}
