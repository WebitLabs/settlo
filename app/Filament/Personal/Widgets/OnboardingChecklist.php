<?php

namespace App\Filament\Personal\Widgets;

use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Personal\Pages\VerifyPhone;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Models\BusinessEntity;
use App\Models\Commune;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * "Get started" checklist on the personal dashboard. Hidden once every item
 * is done; each open item links to the page that completes it. After the
 * email (and phone) verification, "Set up your business" is the first open
 * item, ahead of the home address and tax profile.
 */
class OnboardingChecklist extends Widget
{
    protected string $view = 'filament.personal.widgets.onboarding-checklist';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->isOwner()) {
            return false;
        }

        return collect(self::items($user))->contains(fn (array $item): bool => ! $item['done']);
    }

    /**
     * @return list<array{label: string, done: bool, url: ?string}>
     */
    public static function items(User $user): array
    {
        $businesses = $user->ownedEntities()->orderBy('name')->get(['id', 'name', 'iban']);

        $items = [
            [
                'label' => 'Verify your email',
                'done' => $user->hasVerifiedEmail(),
                'url' => null,
            ],
        ];

        if (config('settlo.phone_verification.enabled')) {
            $items[] = [
                'label' => 'Verify your mobile number',
                'done' => $user->phone_verified_at !== null,
                'url' => VerifyPhone::getUrl(panel: 'app'),
            ];
        }

        $items[] = [
            'label' => 'Set up your business',
            'done' => $businesses->isNotEmpty(),
            'url' => SetUpBusiness::getUrl(panel: 'app'),
        ];

        $items[] = [
            'label' => 'Add your home address',
            'done' => filled($user->street) && filled($user->postal_code) && filled($user->city) && filled($user->canton_id),
            'url' => PersonalProfile::getUrl(panel: 'app'),
        ];

        $items[] = [
            'label' => 'Complete your tax profile',
            'done' => self::taxProfileIsComplete($user),
            'url' => EditTaxProfile::getUrl(panel: 'app'),
        ];

        if ($businesses->isNotEmpty()) {
            $withoutIban = $businesses->first(fn (BusinessEntity $business): bool => blank($business->iban));

            $items[] = [
                'label' => 'Add an IBAN to every business',
                'done' => $withoutIban === null,
                'url' => $withoutIban !== null
                    ? BusinessSettings::getUrl(['tab' => BusinessSettings::INVOICING_TAB], panel: 'workspace', tenant: $withoutIban)
                    : null,
            ];
        }

        return $items;
    }

    /**
     * Whether the owner has enough of a tax profile for the engine to produce
     * an estimate: a canton (plus its commune, where the canton has any) and a
     * marital status. Also used by "Set up a business" to decide whether the
     * new owner still has to be sent here.
     */
    public static function taxProfileIsComplete(User $user): bool
    {
        $profile = $user->taxProfile;

        return $profile !== null
            && filled($profile->canton_id)
            && $profile->marital_status !== null
            && (filled($profile->commune_id) || ! Commune::query()->where('canton_id', $profile->canton_id)->whereNull('effective_to')->exists());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $items = $user instanceof User ? self::items($user) : [];
        $done = count(array_filter($items, fn (array $item): bool => $item['done']));
        $total = count($items);

        return [
            'items' => $items,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }
}
