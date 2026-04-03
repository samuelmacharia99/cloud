<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Payment;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    /**
     * Admin can perform any action on payments.
     */
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    /**
     * Admin can view all payments, customers cannot.
     */
    public function index(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Customers cannot view all payments.');
    }

    /**
     * User can only view their own payments.
     */
    public function view(User $user, Payment $payment): Response
    {
        return $user->id === $payment->user_id
            ? Response::allow()
            : Response::deny('You can only view your own payments.');
    }

    /**
     * User cannot create payments themselves in standard flow.
     * Payments are created by system or admin.
     */
    public function create(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Customers cannot create payments.');
    }

    /**
     * Payments can only be updated by admin for status/notes changes.
     * Cannot edit gateway after creation.
     */
    public function update(User $user, Payment $payment): Response
    {
        if (!$user->is_admin) {
            return Response::deny('Only administrators can update payments.');
        }

        return Response::allow();
    }

    /**
     * Payments cannot be deleted once recorded.
     */
    public function delete(User $user, Payment $payment): Response
    {
        return Response::deny('Payments cannot be deleted. Use reversal instead.');
    }

    /**
     * Only admin can mark payments as reversed.
     */
    public function reverse(User $user, Payment $payment): Response
    {
        if (!$user->is_admin) {
            return Response::deny('Only administrators can reverse payments.');
        }

        if ($payment->status->isFinal() && $payment->status->value !== 'completed') {
            return Response::deny('Only completed payments can be reversed.');
        }

        return Response::allow();
    }

    /**
     * Only admin can reconcile payments (match to invoices).
     */
    public function reconcile(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can reconcile payments.');
    }
}
