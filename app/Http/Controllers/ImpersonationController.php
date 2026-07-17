<?php

namespace App\Http\Controllers;

use App\Services\Audit\ImpersonationService;
use Illuminate\Http\RedirectResponse;

/**
 * Ends an impersonation session from the global banner. The heavy lifting —
 * auditing and restoring the original superadmin — lives in the service; this
 * controller only wires the request to it and returns the admin to their panel.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function stop(): RedirectResponse
    {
        $this->impersonation->stop();

        return redirect('/admin');
    }
}
