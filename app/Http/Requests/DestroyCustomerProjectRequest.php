<?php

namespace App\Http\Requests;

use App\Models\CustomerProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyCustomerProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Typing the project name back is the barrier in front of deleting live
     * sites and their files. An empty project holds nothing to delete, so the
     * same ceremony there is friction with no risk behind it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! $this->destroysServices()) {
            return [];
        }

        return [
            'confirm_name' => ['required', 'string', 'max:100'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_name.required' => 'Type the project name to confirm removal.',
            'confirm.accepted' => 'Confirm that Application Hosting sites in this project will be permanently deleted.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->destroysServices()) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $expected = (string) ($this->project()?->name ?? '');
            $typed = (string) $this->input('confirm_name', '');

            if ($expected === '' || $typed !== $expected) {
                $validator->errors()->add(
                    'confirm_name',
                    'The name does not match this project. Type it exactly as shown.'
                );
            }
        });
    }

    private function destroysServices(): bool
    {
        return (bool) $this->project()?->removalDestroysServices();
    }

    private function project(): ?CustomerProject
    {
        $project = $this->route('project');

        return $project instanceof CustomerProject ? $project : null;
    }
}
