<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 4: two-step sign-in and Paystack billing.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable(); // encrypted
            $table->text('mfa_pending_secret')->nullable(); // encrypted, until confirmed
            $table->json('mfa_recovery_codes')->nullable(); // hashed
            $table->unsignedBigInteger('mfa_last_used_step')->nullable(); // blocks code replay
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('billing_provider', 20)->nullable();
            $table->string('billing_customer_code')->nullable();
            $table->string('billing_subscription_code')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
        });

        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('reference', 100)->unique();
            $table->string('plan', 30);
            $table->unsignedBigInteger('amount'); // minor units (kobo)
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending'); // pending, success, failed, abandoned
            $table->json('provider_data')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Every webhook we accepted, so a replay is processed only once.
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_key', 64)->unique();
            $table->string('event', 60);
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_transactions');
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['billing_provider', 'billing_customer_code', 'billing_subscription_code', 'current_period_end', 'cancel_at_period_end']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'mfa_last_used_step']);
        });
    }
};
