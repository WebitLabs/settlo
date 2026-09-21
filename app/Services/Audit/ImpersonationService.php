<?php

namespace App\Services\Audit;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Manual, package-free impersonation. Only a superadmin may start impersonating,
 * never another superadmin, and both transitions are recorded in the audit trail
 * with the real superadmin captured as the impersonator. The original admin is
 * remembered in the session so stopping restores their identity.
 */
class ImpersonationService
{
    /**
     * Session key holding the real superadmin's id while a session is being
     * impersonated. Its presence is the single source of truth for "am I
     * currently impersonating?".
     */
    public const SESSION_KEY = 'impersonator_id';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Begin impersonating a target user. The caller must be an authenticated
     * superadmin and the target must not itself be a superadmin.
     *
     * @throws AuthorizationException
     */
    public function start(User $target): void
    {
        $admin = Auth::user();

        if (! $admin instanceof User || ! $admin->isSuperadmin()) {
            throw new AuthorizationException('Only a superadmin may impersonate another user.');
        }

        if ($target->isSuperadmin()) {
            throw new AuthorizationException('A superadmin cannot be impersonated.');
        }

        // Nested impersonation would overwrite the remembered admin id with the
        // impersonated user's, so stopping could never restore the real admin —
        // and the session would be stranded as that second user.
        if ($this->isImpersonating()) {
            throw new AuthorizationException('Stop the current impersonation before starting another.');
        }

        session()->put(self::SESSION_KEY, $admin->getKey());

        $this->auditLogger->log('impersonation.started', $target, [
            'target_email' => $target->email,
            'target_role' => $target->role->value,
        ]);

        Auth::login($target);
        $this->refreshSessionPasswordHash($target);
    }

    /**
     * Stop impersonating and restore the original superadmin. No-op when no
     * impersonation is in progress.
     *
     * The remembered admin is re-verified before the identity is handed back:
     * the account may have been demoted, suspended or deleted while the session
     * was impersonating, and restoring it blindly would hand out a superadmin
     * session that could no longer be granted. When it cannot be restored the
     * session is simply logged out.
     */
    public function stop(): void
    {
        $adminId = session()->get(self::SESSION_KEY);

        if ($adminId === null) {
            return;
        }

        $target = Auth::user();
        $admin = User::find($adminId);
        $restorable = $admin instanceof User && $this->mayBeRestored($admin);

        // Logged before the marker is cleared so the row still carries the
        // impersonator, and attributed to the *admin* rather than the
        // impersonated user — they are the one performing this action.
        $this->auditLogger->log(
            $restorable ? 'impersonation.stopped' : 'impersonation.stop_denied',
            $target instanceof User ? $target : null,
            [
                'restored_admin_id' => (int) $adminId,
                'target_email' => $target instanceof User ? $target->email : null,
            ],
            $admin instanceof User ? $admin : null,
        );

        session()->forget(self::SESSION_KEY);

        if (! $restorable) {
            Auth::logout();

            return;
        }

        Auth::login($admin);
        $this->refreshSessionPasswordHash($admin);
    }

    /**
     * The remembered account may only get its session back while it is still a
     * superadmin and still active.
     */
    private function mayBeRestored(User $admin): bool
    {
        return $admin->isSuperadmin() && $admin->status === UserStatus::Active;
    }

    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * The panels run AuthenticateSession, which logs the session out whenever
     * the stored password hash no longer matches the authenticated user. After
     * switching identities the stored hash still belongs to the previous user,
     * so it must be refreshed or the very next request bounces to the login
     * screen.
     */
    private function refreshSessionPasswordHash(User $user): void
    {
        session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $user->getAuthPassword(),
        );
    }
}
