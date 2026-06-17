<?php

namespace App\Services;

use App\Enums\TicketHandledBy;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketRoutingService
{
    public function __construct(
        private ResellerScopeService $resellerScope,
    ) {}

    /**
     * Routing when platform admin creates a ticket on behalf of a customer.
     *
     * @return array{reseller_id: ?int, handled_by: string}
     */
    public function attributesForAdminCreator(User $customer): array
    {
        return [
            'reseller_id' => $customer->reseller_id,
            'handled_by' => TicketHandledBy::Platform->value,
        ];
    }

    /**
     * @return array{reseller_id: ?int, handled_by: string}
     */
    public function attributesForCreator(User $creator): array
    {
        if ($creator->is_reseller) {
            return [
                'reseller_id' => null,
                'handled_by' => TicketHandledBy::Platform->value,
            ];
        }

        if ($creator->reseller_id) {
            return [
                'reseller_id' => $creator->reseller_id,
                'handled_by' => TicketHandledBy::Reseller->value,
            ];
        }

        return [
            'reseller_id' => null,
            'handled_by' => TicketHandledBy::Platform->value,
        ];
    }

    public function isVisibleToAdmin(Ticket $ticket): bool
    {
        return $ticket->isHandledByPlatform();
    }

    public function isResellerCustomerTicket(Ticket $ticket): bool
    {
        return $ticket->reseller_id !== null
            && $ticket->isHandledByReseller();
    }

    public function escalateToPlatform(Ticket $ticket, User $reseller, ?string $note = null): void
    {
        if (! $this->resellerScope->ownsCustomer($reseller, $ticket->user)) {
            throw new \InvalidArgumentException('This ticket does not belong to your customer.');
        }

        if ($ticket->isHandledByPlatform()) {
            return;
        }

        DB::transaction(function () use ($ticket, $reseller, $note) {
            $ticket->update([
                'handled_by' => TicketHandledBy::Platform->value,
                'escalated_at' => now(),
                'escalated_by' => $reseller->id,
                'escalation_note' => filled($note) ? trim($note) : null,
            ]);
        });
    }

    public function owningReseller(Ticket $ticket): ?User
    {
        if (! $ticket->reseller_id) {
            return null;
        }

        return $ticket->relationLoaded('reseller')
            ? $ticket->reseller
            : User::find($ticket->reseller_id);
    }
}
