<?php

namespace App\Filament\App\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Hosts the Ask Settlo React chat as an island inside the panel layout. The
 * page ships an empty root element plus the compiled island bundle; all data
 * arrives through the JSON bootstrap endpoint scoped to the current tenant.
 */
class AskSettlo extends Page
{
    protected string $view = 'filament.app.pages.ask-settlo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Ask Settlo';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getBootstrapUrl(): string
    {
        return route('ask-settlo.bootstrap', Filament::getTenant());
    }
}
