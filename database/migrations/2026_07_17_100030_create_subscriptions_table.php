<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status')->default('trialing');

            // Trial
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('trial_used')->default(false);

            // Billing cycle
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();

            // Scheduled downgrade (applied at period end)
            $table->foreignUuid('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();

            // Human-answer quota (resets monthly, no rollover)
            $table->unsignedInteger('human_answers_used')->default(0);
            $table->unsignedInteger('human_answers_quota')->default(0);
            $table->timestamp('quota_reset_at')->nullable();

            // Payment gateway abstraction — dummy now, Stripe later
            $table->string('gateway')->default('dummy');
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
