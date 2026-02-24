<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('event');
            $table->string('request_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hmac_kid')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'activity_log_tenant_created_idx');
            $table->index(['tenant_id', 'event', 'created_at'], 'activity_log_tenant_event_created_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
