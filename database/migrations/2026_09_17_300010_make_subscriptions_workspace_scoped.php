<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Subscriptions become per business workspace (one subscription per business,
 * many per owner). Existing subscriptions move to the owner's oldest business;
 * every further business receives a copy so current testers keep access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('business_entity_id')->nullable()->after('user_id')
                ->constrained('business_entities')->cascadeOnDelete();
            $table->string('billing_interval')->default('month')->after('plan_id');
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('billing_interval');
            $table->decimal('unit_price', 18, 2)->nullable()->after('discount_percent');
            $table->string('stripe_subscription_type')->nullable()->after('gateway_subscription_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_used_at')->nullable();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_yearly', 18, 2)->nullable()->after('price_monthly');
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_monthly_id')->nullable();
            $table->string('stripe_price_yearly_id')->nullable();
        });

        $this->backfill();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('business_entity_id')->nullable(false)->change();
            $table->unique('business_entity_id');
        });
    }

    public function down(): void
    {
        foreach (DB::table('subscriptions')->distinct()->pluck('user_id') as $userId) {
            $firstId = DB::table('subscriptions')
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->value('id');

            DB::table('subscriptions')
                ->where('user_id', $userId)
                ->where('id', '!=', $firstId)
                ->delete();
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['business_entity_id']);
            $table->dropConstrainedForeignId('business_entity_id');
            $table->dropColumn(['billing_interval', 'discount_percent', 'unit_price', 'stripe_subscription_type']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_used_at');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_yearly', 'stripe_product_id', 'stripe_price_monthly_id', 'stripe_price_yearly_id']);
        });
    }

    /**
     * Attach every subscription to its owner's businesses, record used trials
     * and derive the yearly plan prices.
     */
    private function backfill(): void
    {
        $multiplier = (string) config('settlo.billing.yearly_multiplier', 10);

        foreach (DB::table('plans')->get() as $plan) {
            DB::table('plans')->where('id', $plan->id)->update([
                'price_yearly' => bcmul((string) $plan->price_monthly, $multiplier, 2),
            ]);
        }

        $unitPrices = DB::table('plans')->pluck('price_monthly', 'id');

        foreach (DB::table('subscriptions')->orderBy('created_at')->get() as $subscription) {
            $entityIds = DB::table('business_entities')
                ->where('owner_id', $subscription->user_id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            if ($entityIds->isEmpty()) {
                DB::table('subscriptions')->where('id', $subscription->id)->delete();
                Log::info('Deleted a subscription whose owner has no business.', ['subscription_id' => $subscription->id]);

                continue;
            }

            if ($subscription->trial_used) {
                DB::table('users')->where('id', $subscription->user_id)->whereNull('trial_used_at')
                    ->update(['trial_used_at' => Carbon::now()]);
            }

            DB::table('subscriptions')->where('id', $subscription->id)->update([
                'business_entity_id' => $entityIds->first(),
                'unit_price' => $unitPrices[$subscription->plan_id] ?? null,
            ]);

            foreach ($entityIds->skip(1) as $entityId) {
                $copy = (array) $subscription;
                $copy['id'] = (string) Str::uuid();
                $copy['business_entity_id'] = $entityId;
                $copy['billing_interval'] = 'month';
                $copy['discount_percent'] = 0;
                $copy['unit_price'] = $unitPrices[$subscription->plan_id] ?? null;
                $copy['stripe_subscription_type'] = null;
                $copy['human_answers_used'] = 0;

                DB::table('subscriptions')->insert($copy);

                Log::info('Copied a subscription to an additional business.', [
                    'subscription_id' => $subscription->id,
                    'copy_id' => $copy['id'],
                    'business_entity_id' => $entityId,
                ]);
            }
        }
    }
};
