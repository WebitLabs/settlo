<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Feature flags gated by subscription plan. Enforcement is server-side via
 * policies/middleware — never UI visibility alone.
 */
enum PlanFeature: string implements HasLabel
{
    case TaxEngine = 'tax_engine';
    case AccountantAccess = 'accountant_access';
    case YearEndExport = 'year_end_export';
    case VatForm300 = 'vat_form_300';
    case AnnualReview = 'annual_review';
    case PriorityResponse = 'priority_response';

    public function getLabel(): string
    {
        return match ($this) {
            self::TaxEngine => 'Canton-aware tax engine',
            self::AccountantAccess => 'Human accountant access',
            self::YearEndExport => 'Year-end export',
            self::VatForm300 => 'VAT declaration (Form 300)',
            self::AnnualReview => 'Annual tax return review',
            self::PriorityResponse => 'Priority accountant response',
        };
    }
}
