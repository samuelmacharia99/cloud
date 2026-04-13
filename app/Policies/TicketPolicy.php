<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Models\Service;

class TicketPolicy
{
    /**
     * View all tickets (list page)
     * - Admin: sees all tickets
     * - Reseller: sees own tickets + their customers' tickets
     * - Customer: sees own tickets only
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isReseller() || $user->isCustomer();
    }

    /**
     * View a specific ticket
     * - Admin: can view any ticket
     * - Reseller: can view if they own it or if it belongs to their customer
     * - Customer: can only view their own tickets
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            // Reseller owns the ticket
            if ($ticket->user_id === $user->id) {
                return true;
            }

            // Reseller's customer owns the ticket
            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        // Customer can only view their own tickets
        return $ticket->user_id === $user->id;
    }

    /**
     * Create a new ticket
     * - Any authenticated user can create a ticket
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Reply to a ticket
     * - Admin: can reply to any ticket
     * - Reseller: can reply to their own tickets and their customers' tickets
     * - Customer: can reply to their own tickets if they're open
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            // Reseller owns the ticket
            if ($ticket->user_id === $user->id) {
                return true;
            }

            // Reseller's customer owns the ticket
            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        // Customer can only reply to their own open tickets
        if ($ticket->user_id === $user->id && $ticket->isOpen()) {
            return true;
        }

        return false;
    }

    /**
     * Update ticket status/priority/assignment
     * - Admin: can update any ticket
     * - Reseller: can update their customers' tickets and their own tickets
     * - Customer: cannot update tickets
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            // Reseller owns the ticket
            if ($ticket->user_id === $user->id) {
                return true;
            }

            // Reseller's customer owns the ticket
            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        return false;
    }

    /**
     * Close a ticket
     * - Admin: can close any ticket
     * - Reseller: can close their customers' tickets and their own tickets
     * - Customer: can close their own tickets
     */
    public function close(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReseller()) {
            // Reseller owns the ticket
            if ($ticket->user_id === $user->id) {
                return true;
            }

            // Reseller's customer owns the ticket
            return $this->resellerOwnsCustomer($user, $ticket->user);
        }

        // Customer can close their own tickets
        return $ticket->user_id === $user->id;
    }

    /**
     * Delete a ticket
     * - Only admin can delete tickets
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * Check if a reseller manages a customer
     * A reseller manages a customer if the customer has at least one service
     * with the reseller_id matching the reseller's id
     */
    private function resellerOwnsCustomer(User $reseller, User $customer): bool
    {
        return Service::where('reseller_id', $reseller->id)
            ->where('user_id', $customer->id)
            ->exists();
    }
}
