<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A payment is recorded at most once per gateway reference: Stripe invoice ids
 * for Stripe, and "{gateway}:{subscription}:{period start}" for the locally
 * completed gateways, so replaying an activation can never bill twice. Rows
 * that already share a reference are collapsed onto the oldest one first (a
 * null reference stays exempt, as SQL treats nulls as distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->removeDuplicateReferences();

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->unique(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropUnique(['gateway', 'gateway_reference']);
        });
    }

    /**
     * Keep the oldest row of every duplicated (gateway, gateway_reference)
     * pair and delete the rest, so the unique index can be created.
     */
    private function removeDuplicateReferences(): void
    {
        $duplicates = DB::table('subscription_payments')
            ->select('gateway', 'gateway_reference')
            ->whereNotNull('gateway_reference')
            ->groupBy('gateway', 'gateway_reference')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keptId = DB::table('subscription_payments')
                ->where('gateway', $duplicate->gateway)
                ->where('gateway_reference', $duplicate->gateway_reference)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            $deleted = DB::table('subscription_payments')
                ->where('gateway', $duplicate->gateway)
                ->where('gateway_reference', $duplicate->gateway_reference)
                ->where('id', '!=', $keptId)
                ->delete();

            Log::info('Collapsed duplicate subscription payments onto one gateway reference.', [
                'gateway' => $duplicate->gateway,
                'gateway_reference' => $duplicate->gateway_reference,
                'kept_id' => $keptId,
                'deleted' => $deleted,
            ]);
        }
    }
};
