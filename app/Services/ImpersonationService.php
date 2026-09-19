<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * The single owner of "who is viewing as whom".
 *
 * Impersonation used to be two independent session flags: `impersonating` held
 * an admin id and `impersonating_reseller` held a reseller id. Nothing stopped
 * both from being set at once, which is exactly what happens when an admin
 * impersonates a reseller and that reseller then impersonates one of their own
 * customers. Every reader had to guess which of the two to honour, and they did
 * not agree: the customer layout checked the admin flag first and so offered a
 * way out that skipped the reseller entirely, while the exit route checked the
 * reseller flag first. Leaving cleared only one flag and orphaned the other.
 *
 * Two booleans cannot describe a chain, so this keeps a stack instead. Each
 * frame records who stepped in, the role they did it as, who they became, and
 * where they were standing when they did it. Leaving pops exactly one frame, so
 * a chain unwinds the way it was built and every level lands back where it
 * started.
 */
class ImpersonationService
{
    public const SESSION_KEY = 'impersonation_stack';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_RESELLER = 'reseller';

    /**
     * An admin viewing a reseller viewing their customer is three identities and
     * two frames. Anything deeper is a loop, not a support workflow.
     */
    public const MAX_DEPTH = 3;

    private const LEGACY_ADMIN_KEY = 'impersonating';

    private const LEGACY_RESELLER_KEY = 'impersonating_reseller';

    private const LEGACY_TARGET_KEY = 'impersonating_user_id';

    /**
     * Step into another account, keeping the trail that got us here.
     */
    public function begin(User $actor, User $target, string $actorRole, ?string $originUrl = null): void
    {
        abort_if($target->is_admin, 404);
        abort_unless($this->holdsRole($actor, $actorRole), 403, 'You cannot impersonate from this account.');

        $stack = $this->stack();

        abort_if(
            count($stack) >= self::MAX_DEPTH,
            403,
            'You are already viewing through too many accounts. Step back before viewing as someone else.'
        );

        $stack[] = [
            'actor_id' => (int) $actor->id,
            'actor_role' => $actorRole,
            'actor_name' => (string) $actor->name,
            'target_id' => (int) $target->id,
            'target_name' => (string) $target->name,
            'origin_url' => $this->sanitiseOrigin($originUrl),
            'started_at' => now()->toIso8601String(),
        ];

        $this->becomeUser($target->id);
        $this->write($stack);

        Log::info('Impersonation started', [
            'actor_id' => $actor->id,
            'actor_role' => $actorRole,
            'target_id' => $target->id,
            'depth' => count($stack),
        ]);
    }

    /**
     * Step back out of exactly one level.
     *
     * Returns null when nothing was being impersonated, so callers can treat a
     * stray exit click as a no-op rather than an error.
     */
    public function leave(): ?ImpersonationExit
    {
        $stack = $this->stack();

        if ($stack === []) {
            return null;
        }

        $frame = array_pop($stack);
        $actor = User::find($frame['actor_id'] ?? null);

        // The account we would hand the session back to must still exist and
        // still hold the role it stepped in with. A demoted admin must not get
        // their privileges back by clicking Exit.
        if (! $actor || ! $this->holdsRole($actor, (string) ($frame['actor_role'] ?? ''))) {
            $this->clear();
            Auth::logout();

            abort(403, 'Invalid impersonation session');
        }

        $this->becomeUser($actor->id);

        // Written after the swap: logging in regenerates the session, and the
        // remaining trail has to survive that.
        $this->write($stack);

        Log::info('Impersonation ended', [
            'actor_id' => $actor->id,
            'target_id' => $frame['target_id'] ?? null,
            'remaining_depth' => count($stack),
        ]);

        return new ImpersonationExit(
            actor: $actor,
            returnUrl: $this->resolveReturnUrl($frame),
            remainingDepth: count($stack),
        );
    }

    /**
     * Drop the whole trail. Used when a session ends or begins for real.
     */
    public function clear(): void
    {
        Session::forget([
            self::SESSION_KEY,
            self::LEGACY_ADMIN_KEY,
            self::LEGACY_RESELLER_KEY,
            self::LEGACY_TARGET_KEY,
        ]);
    }

    public function isImpersonating(): bool
    {
        return $this->stack() !== [];
    }

    public function depth(): int
    {
        return count($this->stack());
    }

    /**
     * The frame we are currently standing in, or null at ground level.
     *
     * @return array<string, mixed>|null
     */
    public function currentFrame(): ?array
    {
        $stack = $this->stack();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    /**
     * The whole trail, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function stack(): array
    {
        $this->hydrateLegacySession();

        $stack = Session::get(self::SESSION_KEY, []);

        return is_array($stack) ? array_values($stack) : [];
    }

    /**
     * Sessions that were already open when this shipped still carry the old
     * pair of flags. Rebuild them as a stack on first read so an operator
     * mid-support-call is not stranded with a banner that cannot be dismissed.
     */
    private function hydrateLegacySession(): void
    {
        if (Session::has(self::SESSION_KEY)) {
            return;
        }

        $adminId = Session::get(self::LEGACY_ADMIN_KEY);
        $resellerId = Session::get(self::LEGACY_RESELLER_KEY);
        $targetId = Session::get(self::LEGACY_TARGET_KEY);

        if (! $adminId && ! $resellerId) {
            return;
        }

        $stack = [];

        // Order matters: an admin can only be underneath a reseller, never above
        // one, because the admin hop always happened first.
        if ($adminId) {
            $stack[] = $this->legacyFrame(
                (int) $adminId,
                self::ROLE_ADMIN,
                (int) ($resellerId ?: $targetId),
            );
        }

        if ($resellerId) {
            $stack[] = $this->legacyFrame(
                (int) $resellerId,
                self::ROLE_RESELLER,
                (int) $targetId,
            );
        }

        Session::forget([self::LEGACY_ADMIN_KEY, self::LEGACY_RESELLER_KEY, self::LEGACY_TARGET_KEY]);

        $this->write($stack);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyFrame(int $actorId, string $actorRole, int $targetId): array
    {
        return [
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'actor_name' => (string) (User::find($actorId)?->name ?? 'your account'),
            'target_id' => $targetId,
            'target_name' => (string) (User::find($targetId)?->name ?? 'this account'),
            'origin_url' => null,
            'started_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stack
     */
    private function write(array $stack): void
    {
        if ($stack === []) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, array_values($stack));
    }

    /**
     * Swap the authenticated user, regenerating either side of the login so a
     * fixated session id cannot survive the change of identity.
     */
    private function becomeUser(int $userId): void
    {
        Auth::logout();
        Session::regenerate();
        Auth::loginUsingId($userId);
        Session::regenerate();
    }

    private function holdsRole(User $user, string $role): bool
    {
        return match ($role) {
            self::ROLE_ADMIN => (bool) $user->is_admin,
            self::ROLE_RESELLER => (bool) $user->is_reseller,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function resolveReturnUrl(array $frame): string
    {
        $origin = $this->sanitiseOrigin($frame['origin_url'] ?? null);

        return $origin !== null ? url($origin) : route('dashboard');
    }

    /**
     * Keep only a same-site path, so a crafted origin cannot turn Exit into an
     * open redirect. A leading "//" is a protocol-relative URL, not a path.
     */
    private function sanitiseOrigin(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $candidate = '/'.ltrim($path, '/').(is_string($query) && $query !== '' ? '?'.$query : '');

        return str_starts_with($candidate, '//') ? null : $candidate;
    }
}
