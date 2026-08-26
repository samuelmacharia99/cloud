<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FilterAdminReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('year')) {
            $this->merge(['year' => now()->year]);
        }

        if (! $this->exists('month')) {
            $this->merge(['month' => now()->month]);
        } elseif (in_array($this->input('month'), [null, '', 'all'], true)) {
            $this->merge(['month' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:'.(now()->year + 1)],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function month(): ?int
    {
        $month = $this->validated('month');

        return $month === null ? null : (int) $month;
    }
}
