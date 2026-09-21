<?php

namespace App\Filament\Workspace\Widgets;

use App\Enums\AiEscalationStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Support\SubscriptionBadge;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\AiEscalation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\TaxEstimation;
use App\Services\Tax\VatAlertCopy;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Dashboard to-do list derived live from the tenant's data: billing problems
 * (failed payment, trial ending), overdue invoices, a missing IBAN, VAT alerts, answered-but-unresolved accountant escalations, draft invoices to
 * send, expenses awaiting review, and a prompt to finish the tax profile. Items
 * are colour-coded and capped so the card stays scannable.
 */
class ToDoWidget extends Widget
{
    protected string $view = 'filament.workspace.widgets.to-do';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    private const MAX_ITEMS = 6;

    /** Days before the trial ends from which the reminder is shown. */
    private const TRIAL_REMINDER_DAYS = 5;

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
        $subscription = $entity->subscription;
        $billingUrl = Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app');

        if ($subscription?->status === SubscriptionStatus::PastDue) {
            $items[] = [
                'color' => 'red',
                'icon' => 'heroicon-m-credit-card',
                'label' => 'Payment failed — update your payment method',
                'url' => $billingUrl,
            ];
        }

        $trialDaysLeft = $subscription?->status === SubscriptionStatus::Trialing
            ? SubscriptionBadge::trialDaysLeft($subscription)
            : null;

        if ($trialDaysLeft !== null && $trialDaysLeft <= self::TRIAL_REMINDER_DAYS) {
            $items[] = [
                'color' => 'amber',
                'icon' => 'heroicon-m-clock',
                'label' => match ($trialDaysLeft) {
                    0 => 'Your trial ends today — choose a plan',
                    1 => 'Your trial ends in 1 day — choose a plan',
                    default => "Your trial ends in {$trialDaysLeft} days — choose a plan",
                },
                'url' => $billingUrl,
            ];
        }

        foreach ($this->overdueInvoices($entity) as $invoice) {
            $items[] = [
                'color' => 'red',
                'icon' => 'heroicon-m-exclamation-triangle',
                'label' => "Invoice {$invoice->invoice_number} is overdue",
                'url' => InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $entity),
            ];
        }

        if (blank($entity->iban)) {
            $items[] = [
                'color' => 'amber',
                'icon' => 'heroicon-m-building-library',
                'label' => 'Add your IBAN to send QR invoices',
                'url' => BusinessSettings::getUrl(['tab' => BusinessSettings::INVOICING_TAB], tenant: $entity),
            ];
        }

        if (VatAlertCopy::isActionable($entity->vat_alert_level)) {
            $items[] = [
                'color' => $entity->vat_alert_level === 'warning' ? 'amber' : 'red',
                'icon' => 'heroicon-m-receipt-percent',
                'label' => VatAlertCopy::todo($this->vatEvaluation($entity)),
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
                'label' => "Review & send invoice {$invoice->invoice_number}",
                'url' => InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $entity),
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
                'url' => EditTaxProfile::getUrl(panel: 'app'),
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
            ->overdue()
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

    /**
     * The VAT evaluation snapshotted on the latest estimation, so the to-do
     * item escalates with the same wording as the notification and the widget.
     *
     * @return array<string, mixed>
     */
    private function vatEvaluation(BusinessEntity $entity): array
    {
        $estimation = $entity->latestTaxEstimation((int) config('settlo.current_fiscal_year', now()->year));
        $vat = $estimation instanceof TaxEstimation ? $estimation->inputs['vat'] ?? null : null;

        if (is_array($vat)) {
            return [...$vat, 'level' => $entity->vat_alert_level];
        }

        return [
            'level' => $entity->vat_alert_level,
            'progress_pct' => (float) ($estimation?->vat_threshold_pct ?? 0),
            'threshold' => 100000,
        ];
    }

    private function taxProfileIncomplete(BusinessEntity $entity): bool
    {
        $profile = $entity->ownerTaxProfile();

        return $profile === null || $profile->canton_id === null;
    }
}
