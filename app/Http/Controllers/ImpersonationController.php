<?php

namespace App\Http\Controllers;

use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;

/**
 * The one way back out of impersonation.
 *
 * Admin, reseller and customer layouts all post here. Which account the session
 * returns to is decided by the trail in the session rather than by which button
 * was pressed, so a nested view unwinds one level at a time no matter where the
 * operator clicks Exit.
 */
class ImpersonationController extends Controller
{
    public function leave(ImpersonationService $impersonation): RedirectResponse
    {
        $exit = $impersonation->leave();

        if (! $exit) {
            return redirect()->route('dashboard');
        }

        $message = $exit->remainingDepth > 0
            ? "Back to {$exit->actor->name}. You are still viewing through another account."
            : "Back to your own account, {$exit->actor->name}.";

        return redirect()->to($exit->returnUrl)->with('success', $message);
    }
}
