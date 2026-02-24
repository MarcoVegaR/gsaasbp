<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('provider');
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->string('status')->default('none');
            $table->unsignedBigInteger('provider_object_version')->default(0);
            $table->unsignedBigInteger('subscription_revision')->default(0);
            $table->timestamp('current_period_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider'], 'tenant_subscriptions_tenant_provider_unique');
            $table->index(['tenant_id', 'status'], 'tenant_subscriptions_tenant_status_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('tenant_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('feature');
            $table->boolean('granted')->default(false);
            $table->string('source')->default('billing');
            $table->string('updated_by_event_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature'], 'tenant_entitlements_tenant_feature_unique');
            $table->index(['tenant_id', 'granted'], 'tenant_entitlements_tenant_granted_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('billing_events_processed', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('tenant_id');
            $table->string('provider');
            $table->string('outcome_hash');
            $table->unsignedBigInteger('provider_object_version')->default(0);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'processed_at'], 'billing_processed_tenant_processed_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('billing_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('event_id');
            $table->string('reason');
            $table->string('expected_outcome_hash');
            $table->string('actual_outcome_hash');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'billing_incidents_tenant_created_idx');
            $table->index(['tenant_id', 'event_id'], 'billing_incidents_tenant_event_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_incidents');
        Schema::dropIfExists('billing_events_processed');
        Schema::dropIfExists('tenant_entitlements');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
