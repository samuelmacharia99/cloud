<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Which accounts a white-label host will sign in.
 *
 * A reseller's custom domain is that reseller's portal. Only the reseller and
 * the customers that reseller manages have an account there. Anyone else who
 * authenticates on it (a platform customer, another reseller's customer, an
 * admin) would be handed their own data under a brand that is not theirs,
 * and the reseller's host would carry a session for an account it does not
 * own. Both are boundary crossings, so the host refuses them at every place
 * the auth flow issues a session: login, email verification, and the reset
 * link that leads to one.
 *
 * The platform host stays open to everyone. Reseller customers without a
 * custom domain sign in there and are branded by ResolveResellerTenant.
 */
class ResellerPortalAccessService
{
    /**
     * The reseller whose custom domain served this request, if any.
     * Bound by ResolveResellerTenant.
     */
    public function hostReseller(): ?User
    {
        return app()->bound('currentReseller') ? app('currentReseller') : null;
    }

    public function accountBelongsTo(User $account, User $reseller): bool
    {
        if ((int) $account->id === (int) $reseller->id) {
            return $reseller->is_reseller;
        }

        return $account->reseller_id !== null && (int) $account->reseller_id === (int) $reseller->id;
    }

    public function accountMayUseHost(User $account): bool
    {
        $reseller = $this->hostReseller();

        return $reseller === null || $this->accountBelongsTo($account, $reseller);
    }

    /**
     * Log a refused sign-in so the trail shows which account tried which host.
     */
    public function recordRefusal(User $account, string $stage): void
    {
        Log::warning('Reseller portal: account refused on white-label host', [
            'account_id' => $account->id,
            'account_reseller_id' => $account->reseller_id,
            'host_reseller_id' => $this->hostReseller()?->id,
            'host' => request()?->getHost(),
            'stage' => $stage,
        ]);
    }

    public function refusalMessage(): string
    {
        return 'This account is not registered on this portal. Sign in at the address you registered with.';
    }

    /**
     * The reseller a new account on this request belongs to. A white-label
     * host always registers its own customers; a signed invite link is only
     * honoured on the platform host.
     */
    public function registrationResellerId(): ?int
    {
        $host = $this->hostReseller();
        if ($host !== null) {
            return (int) $host->id;
        }

        $invited = session('registration_reseller_id');

        return $invited ? (int) $invited : null;
    }
}
