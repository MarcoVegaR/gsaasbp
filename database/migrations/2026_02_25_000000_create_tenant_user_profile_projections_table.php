<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user_profile_projections', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('central_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('avatar_url')->nullable();
            $table->boolean('mfa_status')->default(false);
            $table->unsignedBigInteger('profile_version')->default(1);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('stale_after');
            $table->timestamps();

            $table->unique(['tenant_id', 'central_user_id'], 'tupp_tenant_user_unique');
            $table->index(['tenant_id', 'stale_after'], 'tupp_tenant_stale_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_profile_projections');
    }
};
