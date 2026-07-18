<?php

namespace App\Filament\App\Widgets;

use App\Enums\AiEscalationStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Filament\App\Pages\BusinessSettings;
use App\Filament\App\Resources\Expenses\ExpenseResource;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Models\AiEscalation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard to-do list derived live from the tenant's data: overdue invoices,
 * VAT alerts, answered-but-unresolved accountant escalations, draft invoices to
 * send, expenses awaiting review, and a prompt to finish the tax profile. Items
 * are colour-coded and capped so the card stays scannable.
 */
class ToDoWidget extends Widget
{
    protected string $view = 'filament.app.widgets.to-do';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    private const MAX_ITEMS = 6;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entity = Filament::getTenant();

        return [
            'items' => $entity instanceof BusinessEntity
                ? array_slice($this->buildItems($entity), 0, self::MAX_ITEMS)
                : [],
        ];
    }

    /**
     * @return list<array{color: string, icon: string, label: string, url: ?string}>
     */
    private function buildItems(BusinessEntity $entity): array
    {
        $items = [];

        foreach ($this->overdueInvoices($entity) as $invoice) {
            $items[] = [
                'color' => 'red',
                'icon' => 'heroicon-m-exclamation-triangle',
                'label' => "Invoice {$invoice->invoice_number} is overdue",
                'url' => InvoiceResource::getUrl('edit', ['record' => $invoice], tenant: $entity),
            ];
        }

        if ($this->vatNeedsAttention($entity)) {
            $items[] = [
                'color' => 'green',
                'icon' => 'heroicon-m-receipt-percent',
                'label' => 'Consider VAT registration',
                'url' => route('ask-settlo.index', $entity).'?q='.urlencode('Should I register for VAT?'),
            ];
        }

        foreach ($this->answeredEscalations($entity) as $escalation) {
            $items[] = [
                'color' => 'gray',
                'icon' => 'heroicon-m-chat-bubble-left-right',
                'label' => 'Your accountant answered a question',
                'url' => route('ask-settlo.index', $entity),
            ];
        }

        foreach ($this->draftInvoices($entity) as $invoice) {
            $items[] = [
                'color' => 'info',
                'icon' => 'heroicon-m-paper-airplane',
                'label' => "Send invoice {$invoice->invoice_number}",
                'url' => InvoiceResource::getUrl('edit', ['record' => $invoice], tenant: $entity),
            ];
        }

        $pendingExpenses = $this->pendingExpenseCount($entity);
        if ($pendingExpenses > 0) {
            $items[] = [
                'color' => 'amber',
                'icon' => 'heroicon-m-banknotes',
                'label' => $pendingExpenses === 1
                    ? 'Review 1 expense'
                    : "Review {$pendingExpenses} expenses",
                'url' => ExpenseResource::getUrl('index', tenant: $entity),
            ];
        }

        if ($this->taxProfileIncomplete($entity)) {
            $items[] = [
                'color' => 'gray',
                'icon' => 'heroicon-m-user-circle',
                'label' => 'Complete your tax profile',
                'url' => BusinessSettings::getUrl(tenant: $entity),
            ];
        }

        return $items;
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function overdueInvoices(BusinessEntity $entity)
    {
        return $entity->invoices()
            ->where(function ($query): void {
                $query->where('status', InvoiceStatus::Overdue->value)
                    ->orWhere(function ($sent): void {
                        $sent->where('status', InvoiceStatus::Sent->value)
                            ->whereDate('due_date', '<', Carbon::today());
                    });
            })
            ->latest('due_date')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function draftInvoices(BusinessEntity $entity)
    {
        return $entity->invoices()
            ->where('status', InvoiceStatus::Draft->value)
            ->latest('issue_date')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /**
     * @return Collection<int, AiEscalation>
     */
    private function answeredEscalations(BusinessEntity $entity)
    {
        return AiEscalation::query()
            ->where('status', AiEscalationStatus::Answered->value)
            ->whereNull('resolved_at')
            ->whereHas('conversation', fn ($query) => $query->where('business_entity_id', $entity->getKey()))
            ->latest('answered_at')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    private function pendingExpenseCount(BusinessEntity $entity): int
    {
        return $entity->expenses()
            ->where('status', ExpenseStatus::PendingReview->value)
            ->count();
    }

    private function vatNeedsAttention(BusinessEntity $entity): bool
    {
        return in_array($entity->vat_alert_level, ['warning', 'critical', 'mandatory'], true);
    }

    private function taxProfileIncomplete(BusinessEntity $entity): bool
    {
        $profile = $entity->taxProfile;

        return $profile === null || $profile->canton_id === null;
    }
}
