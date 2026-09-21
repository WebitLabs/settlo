<?php

namespace App\Filament\Support;

use App\Enums\ExpenseStatus;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Models\BusinessEntity;
use App\Services\Expenses\ExpenseService;
use Filament\Facades\Filament;

/**
 * Data for the "N expenses awaiting confirmation are not included" notice on
 * the VAT summary, tax estimate and dashboard tax widget.
 */
final class PendingExpensesNotice
{
    /**
     * @return array{count: int, gross: string, url: string}|null null when nothing is pending
     */
    public static function forCurrentTenant(int $fiscalYear): ?array
    {
        $entity = Filament::getTenant();

        if (! $entity instanceof BusinessEntity) {
            return null;
        }

        $pending = app(ExpenseService::class)->pendingSummary($entity, $fiscalYear);

        if ($pending['count'] === 0) {
            return null;
        }

        return [
            ...$pending,
            'url' => ExpenseResource::getUrl('index', [
                'filters' => ['status' => ['value' => ExpenseStatus::PendingReview->value]],
            ], tenant: $entity),
        ];
    }
}
