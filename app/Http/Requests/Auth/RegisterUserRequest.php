<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\ValidCountryCode;
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

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:127'],
            'last_name' => ['nullable', 'string', 'max:127'],
            'country' => ['required', 'string', 'size:2', new ValidCountryCode],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', SecurityService::getPasswordRule()],
            'agree' => ['required', 'accepted'],
            'registration_token' => ['required', 'string'],
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
