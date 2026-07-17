<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path for the append-only audit trail. Every sensitive admin
 * action (suspend/reactivate, impersonation, plan/subscription changes,
 * tax-config edits) records a row here. The service is nullable-safe so it may
 * also be called from queued jobs or the CLI where there is no request, session,
 * or authenticated user.
 */
class AuditLogger
{
    /**
     * Write an audit row. The actor defaults to the authenticated user, the
     * impersonator is resolved from the impersonation session marker when one is
     * present, and the ip/user-agent are read from the current request when it
     * exists.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        $log = new AuditLog;
        $log->forceFill([
            'actor_id' => $actor?->getKey(),
            'impersonator_id' => $this->impersonatorId(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'ip_address' => $this->requestValue(fn ($request): ?string => $request->ip()),
            'user_agent' => $this->userAgent(),
        ])->save();

        return $log;
    }

    /**
     * The real superadmin behind an impersonated session, if any. Reads the
     * marker defensively so a missing session store (jobs/CLI) never throws.
     */
    private function impersonatorId(): ?int
    {
        try {
            $value = session()->get(ImpersonationService::SESSION_KEY);
        } catch (\Throwable) {
            return null;
        }

        return $value !== null ? (int) $value : null;
    }

    /**
     * @param  callable(Request): ?string  $resolver
     */
    private function requestValue(callable $resolver): ?string
    {
        try {
            return $resolver(request());
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        $agent = $this->requestValue(fn ($request): ?string => $request->userAgent());

        return $agent !== null ? mb_substr($agent, 0, 255) : null;
    }
}
