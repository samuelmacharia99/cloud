<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ConnectHermesOllamaRequest extends FormRequest
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
            'ollama_service_id' => ['required', 'integer', 'exists:services,id'],
            'model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ollama_service_id.required' => 'Choose the Ollama service in this project.',
            'ollama_service_id.exists' => 'Choose a valid Ollama service.',
            'model.regex' => 'Choose a valid Ollama model.',
        ];
    }
}
