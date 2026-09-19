<?php

namespace App\Services\Customer;

use App\Models\CustomerProject;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use App\Services\TicketRoutingService;
use Illuminate\Support\Facades\Log;

class CustomerProjectRemovalService
{
    public function __construct(
        private ProvisioningService $provisioning,
    ) {}

    /**
     * Tear down Application Hosting sites in the project, then delete the project.
     * Email / DirectAdmin services are not deleted — refuse if any are still live here.
     *
     * @return array{terminated: int, message: string}
     */
    public function remove(CustomerProject $project, User $actor): array
    {
        if (! $actor->is_admin && (int) $project->user_id !== (int) $actor->id) {
            throw new \InvalidArgumentException('You can only remove your own projects.');
        }

        $project->load(['services.product']);
        $live = $project->liveServices();

        // Same rule the services page offers the button by, read from the
        // project rather than restated here.
        $blocking = $project->removalBlockingServices();
        if ($blocking->isNotEmpty()) {
            $names = $blocking->map(fn (Service $service) => $service->customerServiceName())->implode(', ');

            throw new \InvalidArgumentException(
                'This project still has services that are not Application Hosting ('.$names.'). Move or cancel those first.'
            );
        }

        $failed = [];
        $terminated = 0;

        foreach ($live as $service) {
            try {
                $this->provisioning->terminate($service, false);
                $terminated++;
            } catch (\Throwable $e) {
                Log::error('Project removal could not terminate Application Hosting service', [
                    'project_id' => $project->id,
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = $service->customerServiceName().' ('.$e->getMessage().')';
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(
                'Could not delete every Application Hosting site: '.implode('; ', $failed)
                .'. The project was left so you can retry after the failed sites are cleared.'
            );
        }

        $project->update(['billing_service_id' => null]);
        Service::query()->where('project_id', $project->id)->update(['project_id' => null]);
        $projectName = $project->name;
        $project->delete();

        // Only worth a ticket when something was actually torn down. Clearing an
        // empty heading away is tidying, and opening a support ticket for it
        // would bury the real ones the moment customers start using this.
        if ($terminated > 0) {
            Ticket::create(array_merge([
                'user_id' => $actor->id,
                'title' => 'Project removed: '.$projectName,
                'description' => sprintf(
                    'Customer removed project “%s” and requested teardown of %d Application Hosting site(s), including files and containers.',
                    $projectName,
                    $terminated
                ),
                'status' => 'open',
                'priority' => 'low',
            ], app(TicketRoutingService::class)->attributesForCreator($actor)));
        }

        $message = $terminated > 0
            ? "Project “{$projectName}” removed. {$terminated} Application Hosting site(s) were deleted."
            : "Project “{$projectName}” removed.";

        return [
            'terminated' => $terminated,
            'message' => $message,
        ];
    }
}
