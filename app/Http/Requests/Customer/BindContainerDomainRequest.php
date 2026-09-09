<?php

namespace App\Http\Requests\Customer;

use App\Models\ContainerDomain;
use App\Models\Service;
use App\Services\Provisioning\ContainerNodeWorkloadTopologyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BindContainerDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            && $this->user()?->can('manageContainer', $service) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $existing = ContainerDomain::query()
            ->where('domain', (string) $this->input('domain'))
            ->first();
        $uniqueDomain = Rule::unique('container_domains', 'domain');
        if ($service instanceof Service
            && $existing
            && (int) $existing->container_deployment_id === (int) $service->containerDeployment?->id) {
            $uniqueDomain->ignore($existing->id);
        }

        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)+[a-z]{2,}$/i',
                $uniqueDomain,
            ],
            'purpose' => [
                'sometimes',
                'string',
                Rule::in([ContainerDomain::PURPOSE_WEB, ContainerDomain::PURPOSE_API]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->purpose() !== ContainerDomain::PURPOSE_API) {
                    return;
                }
                $service = $this->route('service');
                if (! $service instanceof Service
                    || ! ContainerNodeWorkloadTopologyService::isApiOnly($service)) {
                    $validator->errors()->add(
                        'purpose',
                        'Dedicated API domains are available only for API-only Node.js services without a browser frontend.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $hostname = strtolower(trim((string) $this->input('domain')));
        $hostname = preg_replace('#^https?://#i', '', $hostname) ?? $hostname;
        $hostname = rtrim(explode('/', $hostname)[0], '.');

        $this->merge([
            'domain' => $hostname,
            'purpose' => $this->input('purpose', ContainerDomain::PURPOSE_WEB),
        ]);
    }

    public function purpose(): string
    {
        return (string) $this->input('purpose', ContainerDomain::PURPOSE_WEB);
    }

    public function hostname(): string
    {
        return (string) $this->validated('domain');
    }
}
