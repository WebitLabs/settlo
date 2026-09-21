<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\FirmPanelProvider;
use App\Providers\Filament\WorkspacePanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
    WorkspacePanelProvider::class,
    FirmPanelProvider::class,
    HorizonServiceProvider::class,
];
