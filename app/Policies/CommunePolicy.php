<?php

namespace App\Policies;

use App\Policies\Concerns\SuperadminOnly;

/**
 * Swiss communes and their tax multipliers are reference data. Only a superadmin may manage them.
 */
class CommunePolicy
{
    use SuperadminOnly;
}
