<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyCustomerProjectRequest extends FormRequest
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
        $validator->after(function (Validator $validator): void {
            $project = $this->route('project');
            $expected = is_object($project) ? (string) $project->name : '';
            $typed = (string) $this->input('confirm_name', '');

            if ($expected === '' || $typed !== $expected) {
                $validator->errors()->add(
                    'confirm_name',
                    'The name does not match this project. Type it exactly as shown.'
                );
            }
        });
    }
}
