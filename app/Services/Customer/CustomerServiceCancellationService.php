<?php

namespace App\Services\Customer;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Support\Facades\Log;

class CustomerServiceCancellationService
{
    public function __construct(
        private ProvisioningService $provisioning,
    ) {}

    /**
     * Cancel a customer service: deprovision infrastructure and record support ticket.
     *
     * @return array{success: bool, deprovisioned: bool, message: string}
     */
    public function cancel(Service $service, User $customer, string $reason): array
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('You can only cancel your own services.');
        }

        if (in_array($service->status->value, ['terminated', 'cancelled'], true)) {
            throw new \InvalidArgumentException('This service is already cancelled or terminated.');
        }

        $deprovisioned = false;
        $warning = null;

        try {
            $this->provisioning->terminate($service->fresh());
            $deprovisioned = true;
        } catch (\Throwable $e) {
            Log::warning('Customer service cancellation: deprovision failed', [
                'service_id' => $service->id,
                'user_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            $service->update(['status' => ServiceStatus::Cancelled]);
            $warning = 'Your cancellation was recorded but automated shutdown failed — support will complete it shortly.';
        }

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Service Cancellation: '.$service->name,
            'description' => $reason,
            'status' => 'open',
            'priority' => 'low',
        ]);

        $message = $deprovisioned
            ? 'Service cancelled and deprovisioned successfully.'
            : ($warning ?? 'Service cancelled. A support ticket has been opened.');

        return [
            'success' => true,
            'deprovisioned' => $deprovisioned,
            'message' => $message,
        ];
    }
}
