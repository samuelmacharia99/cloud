<?php

namespace App\Http\Requests\Admin;

use App\Models\Node;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MigrateContainerServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $currentNodeId = $service instanceof Service
            ? $service->containerDeployment?->node_id
            : null;

        return [
            'target_node_id' => [
                'required',
                'integer',
                Rule::exists('nodes', 'id')->where(
                    fn ($query) => $query
                        ->where('type', 'container_host')
                        ->where('is_active', true)
                        ->whereNotNull('ssh_username')
                        ->where(fn ($credentials) => $credentials
                            ->whereNotNull('ssh_password')
                            ->orWhereNotNull('da_login_key'))
                        ->when($currentNodeId, fn ($q) => $q->where('id', '!=', $currentNodeId))
                ),
            ],
            'reason' => [
                'nullable',
                Rule::in(['planned_maintenance', 'node_failure', 'rebalancing', 'upgrade', 'manual']),
            ],
            'confirm_downtime' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_node_id.exists' => 'Choose a different active container host.',
            'reason.in' => 'Choose a supported migration reason.',
            'confirm_downtime.accepted' => 'Confirm the brief maintenance window before migrating.',
        ];
    }

    public function targetNode(): Node
    {
        return Node::query()->findOrFail((int) $this->validated('target_node_id'));
    }
}
