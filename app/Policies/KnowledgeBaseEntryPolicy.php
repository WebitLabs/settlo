<?php

namespace App\Policies;

use App\Policies\Concerns\SuperadminOnly;

/**
 * Verified Q&A entries are curated platform content. Firm accountants publish them as a side effect of answering an escalation (server-side, not through this policy); only a superadmin may browse, edit or retire them.
 */
class KnowledgeBaseEntryPolicy
{
    use SuperadminOnly;
}
