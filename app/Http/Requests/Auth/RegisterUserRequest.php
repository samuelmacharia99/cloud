<?php

namespace App\Http\Requests\Auth;

use App\Helpers\PhoneHelper;
use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Rules\ValidKenyanMobilePhone;
use App\Services\RegistrationContextService;
use App\Services\RegistrationGuardService;
use App\Services\SecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name') && ! $this->filled('first_name')) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $this->input('name')) ?? '');

            if ($name !== '') {
                $parts = preg_split('/\s+/u', $name, 2) ?: [];
                $this->merge([
                    'first_name' => $parts[0] ?? '',
                    'last_name' => $parts[1] ?? '',
                ]);
            }
        }

        if ($this->filled('phone')) {
            $this->merge([
                'phone' => PhoneHelper::normalize(trim((string) $this->input('phone'))),
            ]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:127'],
            'last_name' => ['nullable', 'string', 'max:127'],
            'country' => ['required', 'string', 'size:2', new ValidCountryCode],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', SecurityService::getPasswordRule()],
            'agree' => ['required', 'accepted'],
            'registration_token' => ['required', 'string'],
        ];

        if (app(RegistrationContextService::class)->requiresPhoneCapture($this)) {
            $rules['phone'] = ['required', 'string', 'max:20', new ValidKenyanMobilePhone];
        } else {
            $rules['phone'] = ['nullable', 'prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Please enter your first name.',
            'phone.required' => 'Please enter your mobile number for SMS verification.',
            'country.required' => 'Please select your country.',
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
            'agree.accepted' => 'You must agree to the Terms of Service and Privacy Policy.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $guard = app(RegistrationGuardService::class);

            if ($message = $guard->validateNamePart((string) $this->input('first_name'), 'first name')) {
                $validator->errors()->add('first_name', $message);
            }

            $lastName = trim((string) $this->input('last_name', ''));
            if ($lastName !== '' && ($message = $guard->validateNamePart($lastName, 'last name'))) {
                $validator->errors()->add('last_name', $message);
            }

            if ($message = $guard->validateEmailDomain((string) $this->input('email'))) {
                $validator->errors()->add('email', $message);
            }
        });
    }
}
