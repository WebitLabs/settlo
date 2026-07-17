<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiQuestionCategory: string implements HasLabel
{
    case TaxDeduction = 'tax_deduction';
    case VatQuestion = 'vat_question';
    case AhvIvEo = 'ahv_iv_eo';
    case IncomeTax = 'income_tax';
    case BusinessStructure = 'business_structure';
    case ExpenseCategorization = 'expense_categorization';
    case GeneralBusiness = 'general_business';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::TaxDeduction => 'Tax deduction',
            self::VatQuestion => 'VAT',
            self::AhvIvEo => 'AHV/IV/EO',
            self::IncomeTax => 'Income tax',
            self::BusinessStructure => 'Business structure',
            self::ExpenseCategorization => 'Expense categorization',
            self::GeneralBusiness => 'General business',
            self::Other => 'Other',
        };
    }
}
