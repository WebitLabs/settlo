<?php

namespace App\Policies;

use App\Policies\Concerns\SuperadminOnly;

/**
 * Swiss cantons are reference data shipped with the app. Only a superadmin may manage them; everyone else reads them indirectly through form options, which are not policy-gated.
 */
class CantonPolicy
{
    use SuperadminOnly;
}
