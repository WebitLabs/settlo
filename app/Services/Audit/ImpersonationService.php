<?php

namespace App\Services\Audit;

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
     */
    public function stop(): void
    {
        $adminId = session()->get(self::SESSION_KEY);

        if ($adminId === null) {
            return;
        }

        $target = Auth::user();
        $admin = User::find($adminId);

        $this->auditLogger->log('impersonation.stopped', $target instanceof User ? $target : null, [
            'restored_admin_id' => (int) $adminId,
        ]);

        if ($admin instanceof User) {
            Auth::login($admin);
            $this->refreshSessionPasswordHash($admin);
        }

        session()->forget(self::SESSION_KEY);
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
