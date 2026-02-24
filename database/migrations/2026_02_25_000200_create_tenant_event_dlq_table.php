<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_event_dlq', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id');
            $table->string('tenant_id');
            $table->string('event_name');
            $table->string('schema_version');
            $table->unsignedInteger('retry_count')->default(0);
            $table->json('payload');
            $table->text('failure_reason');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'tedlq_tenant_created_idx');
            $table->index(['tenant_id', 'event_id'], 'tedlq_tenant_event_id_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_event_dlq');
    }
};
