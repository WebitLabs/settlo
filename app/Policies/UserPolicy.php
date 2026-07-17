<?php

namespace App\Policies;

use App\Models\User;

/**
 * Superadmin-only administration of user accounts. Reading and updating (the
 * status transitions behind suspend/reactivate) are allowed for superadmins;
 * creation and deletion are denied outright — the POC never removes users, and
 * accounts are provisioned through the normal onboarding flow.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
