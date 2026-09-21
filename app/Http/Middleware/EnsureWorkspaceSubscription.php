<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Models\BusinessEntity;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A workspace without a subscription, or whose checkout is not completed
 * (Incomplete), is locked: every page redirects to Billing. Expired and
 * cancelled workspaces stay readable (policies block writes, a banner explains).
 * Right after a completed checkout Billing only says the payment is being
 * confirmed instead of asking for a plan again.
 */
class EnsureWorkspaceSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof BusinessEntity) {
            return $next($request);
        }

        $subscription = $tenant->subscription;

        if ($subscription === null || $subscription->status === SubscriptionStatus::Incomplete) {
            if (Billing::checkoutIsPending()) {
                Notification::make()
                    ->title('Payment received, activating…')
                    ->body("{$tenant->name} opens as soon as Stripe confirms the payment.")
                    ->info()
                    ->send();
            } else {
                Notification::make()
                    ->title("Choose a plan to open {$tenant->name}")
                    ->body('This business becomes available as soon as the payment is completed.')
                    ->warning()
                    ->send();
            }

            return redirect()->to(Billing::getUrl(['workspace' => $tenant->getKey()], panel: 'app'));
        }

        return $next($request);
    }
}
