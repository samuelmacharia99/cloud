<?php

namespace App\Http\Requests\Customer;

use App\Models\Service;
use App\Services\TechStackRoutingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RedeployContainerStackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            && $this->user()?->can('manageContainer', $service) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $template = $service instanceof Service ? $service->effectiveContainerTemplate() : null;
        $versionRules = ['nullable', 'string', 'max:32'];
        if (($template?->slug ?? '') === 'nodejs') {
            $versionRules[] = Rule::in(TechStackRoutingService::allowedSelectedVersions($template));
        } else {
            $versionRules[] = 'prohibited';
        }
        $rootRules = in_array($template?->slug, ['nodejs', 'python', 'ruby', 'go'], true)
            ? ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', 'not_regex:/\.\./']
            : ['prohibited'];

        return [
            'framework' => ['nullable', 'string', 'max:64'],
            'frontend' => ['nullable', 'string', 'max:64'],
            'database_id' => ['nullable', 'integer', 'exists:database_templates,id'],
            'selected_version' => $versionRules,
            'backend_root' => $rootRules,
            'frontend_root' => $rootRules,
            'reset_database' => ['sometimes', 'boolean'],
            'replace_application' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'selected_version.in' => 'Choose a supported Node.js runtime version or Auto detect.',
            'selected_version.prohibited' => 'Runtime version selection is only available for Node.js services.',
            'backend_root.regex' => 'Backend root must be a relative repository directory such as apps/api.',
            'frontend_root.regex' => 'Frontend root must be a relative repository directory such as apps/web.',
        ];
    }
}
