<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;
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
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
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

            if ($message = $guard->validateHumanName((string) $this->input('name'))) {
                $validator->errors()->add('name', $message);
            }

            if ($message = $guard->validateEmailDomain((string) $this->input('email'))) {
                $validator->errors()->add('email', $message);
            }
        });
    }
}
