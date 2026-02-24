<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_event_deduplications', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('tenant_id');
            $table->string('event_name');
            $table->string('schema_version');
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'event_name'], 'ted_tenant_event_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_event_deduplications');
    }
};
