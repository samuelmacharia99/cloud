<?php

namespace App\Services;

use App\Models\User;

/**
 * The result of stepping out of one level of impersonation: who the session
 * belongs to again, and where that person should be put back down.
 */
final class ImpersonationExit
{
    public function __construct(
        public readonly User $actor,
        public readonly string $returnUrl,
        public readonly int $remainingDepth,
    ) {}
}
