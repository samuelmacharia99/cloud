<?php

namespace App\Http\Requests\Customer;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class RestartStackMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service && (bool) $this->user()?->can('manageContainer', $service);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['member' => (string) $this->route('member')]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // A compose service key; it is verified against the parsed compose
            // file before any command runs, this only keeps junk out of the log.
            'member' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9_.-]*$/i'],
        ];
    }

    public function composeKey(): string
    {
        return (string) $this->validated('member');
    }
}
