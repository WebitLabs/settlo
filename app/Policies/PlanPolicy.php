<?php

namespace App\Policies;

use App\Policies\Concerns\SuperadminOnly;

/**
 * Subscription plans are platform configuration: only a superadmin may list or change them. Owners read plan data through the billing pages, which are not policy-gated resources.
 */
class PlanPolicy
{
    use SuperadminOnly;
}
