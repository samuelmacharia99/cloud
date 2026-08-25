<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Models\DomainExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Domain $domain */
        $domain = $this->route('domain');
        $extension = format_domain_extension((string) $this->input('extension'));

        return [
            'name' => [
                'required',
                'string',
                'max:253',
                'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i',
                Rule::unique('domains', 'name')
                    ->where(fn ($query) => $query->where('extension', $extension))
                    ->ignore($domain->id),
            ],
            'extension' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail) use ($domain): void {
                    $normalized = format_domain_extension((string) $value);
                    $allowed = DomainExtension::query()->pluck('extension')->all();
                    $allowed[] = $domain->extension;

                    if (! in_array($normalized, array_unique($allowed), true)) {
                        $fail('Select a configured domain extension.');
                    }
                },
            ],
            'registrar' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending,active,expired,suspended'],
            'registered_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'nameserver_1' => ['nullable', 'string', 'max:253'],
            'nameserver_2' => ['nullable', 'string', 'max:253'],
            'notes' => ['nullable', 'string'],
            'auto_renew' => ['nullable', 'boolean'],
            'confirm_local_rename' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Domain $domain */
            $domain = $this->route('domain');

            if ($this->requiresLocalRenameConfirmation($domain) && ! $this->boolean('confirm_local_rename')) {
                $validator->errors()->add(
                    'confirm_local_rename',
                    'Confirm that renaming updates Talksasa only and does not change the registrar record.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another domain record already uses this name and extension.',
            'name.regex' => 'Enter a valid domain label (letters, numbers, hyphens, optional sub-labels).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => strtolower(rtrim(trim((string) $this->input('name')), '.')),
            'extension' => format_domain_extension((string) $this->input('extension')),
        ]);
    }

    private function requiresLocalRenameConfirmation(Domain $domain): bool
    {
        if (! $domain->isLinkedToRegistrarApi()) {
            return false;
        }

        $name = strtolower(rtrim(trim((string) $this->input('name')), '.'));
        $extension = format_domain_extension((string) $this->input('extension'));

        return strcasecmp((string) $domain->name, $name) !== 0
            || strcasecmp((string) $domain->extension, $extension) !== 0;
    }
}
