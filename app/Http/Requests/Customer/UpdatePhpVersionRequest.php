<?php

namespace App\Http\Requests\Customer;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePhpVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service && (bool) $this->user()?->can('manageContainer', $service);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Empty means "back to the stack default"; the value itself is
            // checked against the stack's offered versions by the service.
            'version' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
        ];
    }

    public function version(): ?string
    {
        $version = $this->validated('version');

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }
}
