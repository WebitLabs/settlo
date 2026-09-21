<?php

namespace App\Filament\Personal\Widgets;

use App\Filament\Personal\Pages\EditTaxProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * A summary of the owner's personal tax profile with a link to edit it.
 */
class TaxProfileCard extends Widget
{
    protected string $view = 'filament.personal.widgets.tax-profile-card';

    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $profile = $user->taxProfile()->with(['canton', 'commune'])->first();

        $rows = $profile === null ? [] : [
            'Tax residence' => collect([$profile->commune?->name, $profile->canton?->code])->filter()->implode(', ') ?: '—',
            'Residence status' => $profile->residence_permit?->getLabel() ?? '—',
            'Marital status' => $profile->marital_status?->getLabel() ?? '—',
            'Children' => (string) ($profile->number_of_children ?? 0),
            'Pillar 3a' => 'CHF '.number_format((float) $profile->pillar3a_amount, 0, '.', "'"),
        ];

        return [
            'rows' => $rows,
            'editUrl' => EditTaxProfile::getUrl(panel: 'app'),
        ];
    }
}
