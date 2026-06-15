<?php

namespace App\Policies;

use App\Enums\TicketHandledBy;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketRoutingService;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isReseller() || $user->isCustomer();
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return app(TicketRoutingService::class)->isVisibleToAdmin($ticket);
        }

        if ($user->isReseller()) {
            if ($ticket->user_id === $user->id) {
                return true;
            }

            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        return $ticket->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        if (! $this->view($user, $ticket)) {
            return false;
        }

        if ($user->isAdmin() || $user->isReseller()) {
            return true;
        }

        return $ticket->user_id === $user->id && $ticket->isOpen();
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return app(TicketRoutingService::class)->isVisibleToAdmin($ticket);
        }

        if ($user->isReseller()) {
            if ($ticket->user_id === $user->id) {
                return true;
            }

            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        return false;
    }

    public function close(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return app(TicketRoutingService::class)->isVisibleToAdmin($ticket);
        }

        if ($user->isReseller()) {
            if ($ticket->user_id === $user->id) {
                return true;
            }

            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        return $ticket->user_id === $user->id;
    }

    public function escalate(User $user, Ticket $ticket): bool
    {
        if (! $user->isReseller()) {
            return false;
        }

        if ($ticket->user_id === $user->id) {
            return false;
        }

        if (! $this->resellerOwnsCustomer($user, $ticket->user)) {
            return false;
        }

        return $ticket->handled_by === TicketHandledBy::Reseller;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return app(TicketRoutingService::class)->isVisibleToAdmin($ticket);
    }

    private function resellerOwnsCustomer(User $reseller, User $customer): bool
    {
        if ($customer->reseller_id === $reseller->id) {
            return true;
        }

        return Service::where('reseller_id', $reseller->id)
            ->where('user_id', $customer->id)
            ->exists();
    }
}
