<?php

namespace App\Services\Ai;

use App\Enums\VatStatus;
use App\Models\BusinessEntity;
use App\Models\User;

/**
 * Builds the Settlo AI system prompt fresh on every call from live data so the
 * assistant always reasons over the user's current figures. Also exposes the
 * raw snapshot values for persistence alongside the assistant message.
 */
class ChatContextAssembler
{
    public function assemble(User $user, BusinessEntity $entity): ChatContext
    {
        $fiscalYear = (int) config('settlo.current_fiscal_year', (int) date('Y'));
        $entity->loadMissing(['owner.taxProfile.canton', 'canton']);
        $profile = $entity->ownerTaxProfile();

        $firstName = trim((string) $user->first_name);
        $lastName = trim((string) $user->last_name);
        $entityName = trim((string) $entity->name);
        $cantonCode = $profile?->canton?->code ?? $entity->canton?->code ?? 'CH';
        $revenueYtd = $this->revenueYtd($entity, $fiscalYear);
        $vatStatusLabel = $this->vatStatusLabel($entity, $fiscalYear);
        $maritalStatusLabel = $profile?->marital_status?->getLabel() ?? 'Single';
        $numberOfChildren = (int) ($profile?->number_of_children ?? 0);
        $pillar3a = (float) ($profile?->pillar3a_amount ?? 0);

        $systemPrompt = $this->buildPrompt(
            firstName: $firstName,
            lastName: $lastName,
            entityName: $entityName,
            cantonCode: $cantonCode,
            revenueYtd: $revenueYtd,
            vatStatusLabel: $vatStatusLabel,
            maritalStatusLabel: $maritalStatusLabel,
            numberOfChildren: $numberOfChildren,
            pillar3a: $pillar3a,
        );

        return new ChatContext(
            systemPrompt: $systemPrompt,
            snapshot: [
                'fiscal_year' => $fiscalYear,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'entity_name' => $entityName,
                'canton_code' => $cantonCode,
                'revenue_ytd' => $revenueYtd,
                'vat_status' => $entity->vat_status?->value,
                'vat_status_label' => $vatStatusLabel,
                'vat_status_short' => $this->vatStatusShortLabel($entity),
                'marital_status' => $profile?->marital_status?->value,
                'number_of_children' => $numberOfChildren,
                'pillar3a_amount' => $pillar3a,
            ],
        );
    }

    private function buildPrompt(
        string $firstName,
        string $lastName,
        string $entityName,
        string $cantonCode,
        float $revenueYtd,
        string $vatStatusLabel,
        string $maritalStatusLabel,
        int $numberOfChildren,
        float $pillar3a,
    ): string {
        $fullName = trim("{$firstName} {$lastName}") ?: 'the user';
        $revenue = $this->swissAmount($revenueYtd);
        $pillar = $this->swissAmount($pillar3a);

        return 'You are Settlo AI, a Swiss tax and business assistant for self-employed professionals. '
            ."User: {$fullName} ({$entityName}), sole proprietor, canton {$cantonCode}, "
            ."CHF {$revenue} revenue YTD, VAT status {$vatStatusLabel}, marital status {$maritalStatusLabel}, "
            ."{$numberOfChildren} children, Pillar 3a CHF {$pillar}/year. "
            .'Answer questions about Swiss taxes, AHV/IV/EO, VAT, and business. Be specific and concise, '
            .'give confident answers, and reference Swiss law when relevant. '
            .'Keep answers under about 350 words; prefer short paragraphs and bullet lists. '
            .'Only suggest verifying with a certified Swiss accountant when you are genuinely uncertain — never as a default disclaimer. '
            .'Never mention the underlying AI model or provider — you are Settlo AI.';
    }

    private function revenueYtd(BusinessEntity $entity, int $fiscalYear): float
    {
        return (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $fiscalYear)
            ->sum('subtotal');
    }

    private function vatStatusLabel(BusinessEntity $entity, int $fiscalYear): string
    {
        if ($entity->vat_status !== null && $entity->vat_status !== VatStatus::NotRegistered) {
            return $entity->vat_status->getLabel();
        }

        if (filled($entity->mwst_number)) {
            return 'Registered';
        }

        $thresholdPct = $entity->latestTaxEstimation($fiscalYear)?->vat_threshold_pct;

        if ($thresholdPct !== null) {
            return "Not registered ({$thresholdPct}% of the CHF 100'000 threshold)";
        }

        return 'Not registered';
    }

    /**
     * A compact VAT status for the chat header pill ("Not registered", "Registered", "Exempt").
     */
    private function vatStatusShortLabel(BusinessEntity $entity): string
    {
        if ($entity->vat_status !== null && $entity->vat_status !== VatStatus::NotRegistered) {
            return $entity->vat_status->getShortLabel();
        }

        return filled($entity->mwst_number) ? 'Registered' : 'Not registered';
    }

    private function swissAmount(float $value): string
    {
        return number_format($value, 0, '.', "'");
    }
}
