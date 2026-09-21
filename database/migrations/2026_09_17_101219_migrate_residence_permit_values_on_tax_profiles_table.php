<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps the two legacy residence permit values onto the new "Residence status"
 * list. "b_permit" becomes the EU/EFTA B permit (a best guess; every B status is
 * handled identically by the tax engine).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const array MAPPING = [
        'swiss_or_c' => 'swiss',
        'b_permit' => 'eu_efta_b',
    ];

    public function up(): void
    {
        foreach (self::MAPPING as $legacy => $current) {
            DB::table('tax_profiles')->where('residence_permit', $legacy)->update(['residence_permit' => $current]);
        }

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->string('residence_permit')->default('swiss')->change();
        });
    }

    public function down(): void
    {
        DB::table('tax_profiles')->whereIn('residence_permit', ['swiss', 'eu_efta_c', 'non_eu_c'])->update(['residence_permit' => 'swiss_or_c']);
        DB::table('tax_profiles')->whereNotIn('residence_permit', ['swiss_or_c', 'b_permit'])->update(['residence_permit' => 'b_permit']);

        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->string('residence_permit')->default('swiss_or_c')->change();
        });
    }
};
