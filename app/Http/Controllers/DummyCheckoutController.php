<?php

namespace App\Http\Controllers;

use App\Billing\DummyGateway;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The local "checkout" of the dummy gateway: a signed URL that activates the
 * owner's workspace subscription and returns to the requested page. Only
 * reachable while the dummy gateway is bound and allowed (never in production).
 */
class DummyCheckoutController extends Controller
{
    public function __invoke(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless(DummyGateway::isAllowed(), 404);

        $subscriptions = app(SubscriptionService::class);

        abort_unless($subscriptions->gateway()->name() === 'dummy', 404);
        abort_unless($subscription->user_id === $request->user()?->getKey(), 403);

        $subscriptions->activate($subscription);

        $return = (string) $request->query('return', '');

        return redirect()->to($this->isLocalUrl($return) ? $return : url('/app'));
    }

    /**
     * Only redirect back into this application (open-redirect guard).
     */
    private function isLocalUrl(string $url): bool
    {
        return $url !== '' && (str_starts_with($url, url('/').'/') || $url === url('/'));
    }
}
