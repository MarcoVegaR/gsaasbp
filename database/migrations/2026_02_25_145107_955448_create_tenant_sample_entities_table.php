<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sample_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id']);
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX "tenant_sample_entities_tenant_id_title_live_unique" ON "tenant_sample_entities" ("tenant_id", "title") WHERE "deleted_at" IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sample_entities');
    }
};
