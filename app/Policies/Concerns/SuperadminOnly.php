<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Default-deny ability set for platform-owned records that only exist inside the
 * superadmin panel (plans, reference data, tax configuration, the knowledge
 * base). Every ability is declared explicitly: Filament treats a *missing*
 * policy method as "allowed", so silence would be a hole rather than a denial.
 */
trait SuperadminOnly
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Model $record): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Model $record): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->isSuperadmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, Model $record): bool
    {
        return $user->isSuperadmin();
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $user->isSuperadmin();
    }
}
