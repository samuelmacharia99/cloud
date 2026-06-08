<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): Response
    {
        return $user->id === $invoice->user_id
            ? Response::allow()
            : Response::deny('You can only view your own invoices.');
    }

    public function download(User $user, Invoice $invoice): Response
    {
        return $this->view($user, $invoice);
    }

    public function pay(User $user, Invoice $invoice): Response
    {
        if ($user->id !== $invoice->user_id) {
            return Response::deny('You can only pay your own invoices.');
        }

        if ($invoice->status->value === 'paid') {
            return Response::deny('This invoice is already paid.');
        }

        return Response::allow();
    }
}
