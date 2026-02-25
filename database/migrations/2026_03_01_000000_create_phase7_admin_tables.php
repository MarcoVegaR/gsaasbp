<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_hard_delete_approvals', function (Blueprint $table): void {
            $table->string('approval_id')->primary();
            $table->string('tenant_id');
            $table->unsignedBigInteger('requested_by_platform_user_id');
            $table->unsignedBigInteger('approved_by_platform_user_id');
            $table->unsignedBigInteger('executor_platform_user_id');
            $table->string('reason_code');
            $table->string('signature');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at'], 'platform_hard_delete_approvals_tenant_expires_idx');
            $table->index(['executor_platform_user_id', 'consumed_at'], 'platform_hard_delete_approvals_executor_consumed_idx');
        });

        Schema::create('platform_forensic_exports', function (Blueprint $table): void {
            $table->string('export_id')->primary();
            $table->unsignedBigInteger('platform_user_id');
            $table->string('reason_code');
            $table->json('filters');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('download_token_hash', 64)->nullable();
            $table->timestamp('download_token_expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['platform_user_id', 'created_at'], 'platform_forensic_exports_actor_created_idx');
            $table->index(['download_token_hash', 'downloaded_at'], 'platform_forensic_exports_token_consumed_idx');
        });

        Schema::create('platform_impersonation_sessions', function (Blueprint $table): void {
            $table->string('jti')->primary();
            $table->unsignedBigInteger('platform_user_id');
            $table->string('target_tenant_id');
            $table->unsignedBigInteger('target_user_id');
            $table->string('reason_code');
            $table->string('fingerprint');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['platform_user_id', 'revoked_at'], 'platform_impersonation_sessions_actor_revoked_idx');
            $table->index(['target_tenant_id', 'target_user_id'], 'platform_impersonation_sessions_target_idx');
            $table->index(['expires_at', 'revoked_at'], 'platform_impersonation_sessions_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_impersonation_sessions');
        Schema::dropIfExists('platform_forensic_exports');
        Schema::dropIfExists('platform_hard_delete_approvals');
    }
};
