<?php

namespace App\Services;

use App\Exceptions\ResellerBoundaryException;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Who the platform may speak to directly.
 *
 * A reseller's customers are the reseller's. They see the reseller's brand,
 * receive the reseller's mail and SMS, and never hear from Talksasa itself.
 * System notifications already honour that (EmailDeliveryService routes them
 * through the reseller's SMTP with no platform fallback, NotificationService
 * does the same for SMS). This is the same rule for the places where an
 * admin, not the system, presses Send: bulk email, the SMS page, welcome and
 * login-credentials mail, resending a service's credentials.
 *
 * Every refusal is logged with the actor, so the trail shows who tried.
 */
class ResellerBoundaryService
{
    /**
     * Customers the platform itself owns: not admins, not resellers, not
     * anyone a reseller manages.
     */
    public function platformCustomers(): Builder
    {
        return User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('reseller_id');
    }

    public function isResellerManaged(User $user): bool
    {
        return $user->reseller_id !== null;
    }

    public function isPlatformCustomer(User $user): bool
    {
        return ! $user->is_admin && ! $user->is_reseller && ! $this->isResellerManaged($user);
    }

    /**
     * @throws ResellerBoundaryException
     */
    public function assertPlatformMayContact(User $recipient, string $channel, string $purpose): void
    {
        if (! $this->isResellerManaged($recipient)) {
            return;
        }

        $recipient->loadMissing('reseller');
        $resellerName = $recipient->reseller?->name ?? 'their reseller';

        Log::warning('Reseller boundary: platform contact refused', [
            'actor_id' => auth()->id(),
            'recipient_id' => $recipient->id,
            'reseller_id' => $recipient->reseller_id,
            'channel' => $channel,
            'purpose' => $purpose,
        ]);

        throw new ResellerBoundaryException(
            "{$recipient->name} is a customer of {$resellerName}. {$resellerName} contacts them, not the platform; "
            ."send {$purpose} from the reseller portal or ask the reseller to."
        );
    }

    /**
     * Split a list of user ids into the platform customers among them and
     * the number that were refused.
     *
     * @param  list<int>  $userIds
     * @return array{allowed: Collection<int, User>, refused: int}
     */
    public function partitionPlatformRecipients(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return ['allowed' => new Collection, 'refused' => 0];
        }

        $allowed = $this->platformCustomers()->whereIn('id', $ids)->get();
        $refused = count($ids) - $allowed->count();

        if ($refused > 0) {
            Log::warning('Reseller boundary: recipients dropped from a platform send', [
                'actor_id' => auth()->id(),
                'requested' => count($ids),
                'refused' => $refused,
            ]);
        }

        return ['allowed' => $allowed, 'refused' => $refused];
    }
}
