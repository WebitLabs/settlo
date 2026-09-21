<?php

namespace App\Policies;

use App\Policies\Concerns\SuperadminOnly;

/**
 * Effective-dated Swiss tax configuration. Wrong values silently corrupt every estimate, so the whole surface is superadmin-only.
 */
class SocialInsuranceRatePolicy
{
    use SuperadminOnly;
}
