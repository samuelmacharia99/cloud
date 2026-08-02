<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveCustomerServiceProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('project_id') === '' || $this->input('project_id') === '0') {
            $this->merge(['project_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = (int) $this->user()?->id;

        return [
            'project_id' => [
                'nullable',
                Rule::exists('customer_projects', 'id')->where(fn ($q) => $q->where('user_id', $userId)),
            ],
        ];
    }
}
