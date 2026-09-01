<?php

namespace App\Http\Requests\Customer;

use App\Services\Provisioning\ContainerOllamaModelService;
use Illuminate\Foundation\Http\FormRequest;

class ChatContainerOllamaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('message')) {
            $this->merge([
                'message' => trim((string) $this->input('message')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = ContainerOllamaModelService::MAX_MESSAGE_CHARS;
        $history = ContainerOllamaModelService::MAX_HISTORY;

        return [
            'message' => ['required', 'string', 'min:1', 'max:'.$max],
            'model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/'],
            'history' => ['nullable', 'array', 'max:'.$history],
            'history.*.role' => ['required_with:history', 'in:user,assistant,system'],
            'history.*.content' => ['required_with:history', 'string', 'max:'.$max],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Write a message to send to the model.',
            'message.max' => 'Keep each message under '.ContainerOllamaModelService::MAX_MESSAGE_CHARS.' characters.',
            'model.regex' => 'Choose a valid Ollama model.',
        ];
    }
}
