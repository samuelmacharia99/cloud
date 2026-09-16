<?php

namespace App\Http\Requests\Customer;

use App\Services\Provisioning\WordPressAdminAccountService;
use Illuminate\Foundation\Http\FormRequest;

class ResetWordPressAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:generate,custom'],
            'password' => [
                'exclude_unless:mode,custom',
                'required',
                'string',
                'min:'.WordPressAdminAccountService::MIN_PASSWORD_LENGTH,
                'max:'.WordPressAdminAccountService::MAX_PASSWORD_LENGTH,
                'confirmed',
            ],
        ];
    }

    public function chosenPassword(): ?string
    {
        return $this->input('mode') === 'custom' ? (string) $this->validated('password') : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Type the new password, or choose a generated one.',
            'password.min' => 'Use at least '.WordPressAdminAccountService::MIN_PASSWORD_LENGTH.' characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
