<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_tokens', function (Blueprint $table): void {
            $table->string('jti')->primary();
            $table->string('tenant_id');
            $table->string('sub');
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('central_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'consumed_at'], 'invite_tokens_tenant_consumed_idx');
            $table->unique(['tenant_id', 'central_user_id'], 'invite_tokens_tenant_user_unique');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_tokens');
    }
};
