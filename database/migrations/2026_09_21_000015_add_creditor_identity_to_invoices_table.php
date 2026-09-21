<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the creditor snapshot frozen when an invoice is issued: an issued
 * invoice must keep showing the legal name, UID and VAT registration it was
 * issued with, even after the business later renames itself, moves or
 * registers for VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('creditor_legal_name')->nullable()->after('creditor_name');
            $table->string('creditor_uid')->nullable()->after('creditor_country');
            $table->string('creditor_vat_number')->nullable()->after('creditor_uid');
            $table->boolean('creditor_vat_registered')->nullable()->after('creditor_vat_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'creditor_legal_name',
                'creditor_uid',
                'creditor_vat_number',
                'creditor_vat_registered',
            ]);
        });
    }
};
