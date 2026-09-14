<?php

namespace App\Http\Requests\Customer;

class MoveContainerPathsRequest extends BatchContainerPathsRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'destination' => self::pathRules(),
            'mode' => ['required', 'in:move,copy'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'destination.required' => 'Choose a destination folder.',
            'destination.regex' => 'Destination must start with "/"',
            'destination.not_regex' => 'Destination contains invalid characters or traversal segments',
            'mode.in' => 'Mode must be move or copy.',
        ]);
    }

    public function isCopy(): bool
    {
        return $this->validated('mode') === 'copy';
    }

    public function destination(): string
    {
        return (string) $this->validated('destination');
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['destination' => self::normalizePath($this->input('destination'))]);
    }
}
