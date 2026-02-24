<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_step_up_capabilities', function (Blueprint $table): void {
            $table->string('capability_id')->primary();
            $table->foreignId('platform_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id', 191);
            $table->string('device_fingerprint', 191);
            $table->string('scope', 191);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_user_id', 'scope', 'expires_at'], 'platform_step_up_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_step_up_capabilities');
    }
};
