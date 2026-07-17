<?php

namespace App\Console\Commands;

use App\Services\Invoicing\InvoiceService;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'settlo:mark-overdue-invoices';

    protected $description = 'Flip past-due sent invoices to overdue.';

    public function handle(InvoiceService $invoices): int
    {
        $count = $invoices->markOverdue();

        $this->info("Marked {$count} invoice(s) as overdue.");

        return self::SUCCESS;
    }
}
