<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user_realtime_epochs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('authz_epoch')->default(1);
            $table->timestamp('last_bumped_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'tur_epochs_tenant_user_unique');
            $table->index(['tenant_id', 'authz_epoch'], 'tur_epochs_tenant_epoch_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('tenant_notification_stream_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('notifiable_id')->constrained('users')->cascadeOnDelete();
            $table->string('stream_key', 190);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'notifiable_id', 'stream_key'],
                'tnss_tenant_notifiable_stream_unique',
            );
            $table->index(['tenant_id', 'stream_key'], 'tnss_tenant_stream_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('tenant_notification_outbox', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('event_id');
            $table->string('tenant_id');
            $table->foreignId('notifiable_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 120);
            $table->unsignedInteger('version')->default(1);
            $table->json('payload')->nullable();
            $table->string('stream_key', 190);
            $table->unsignedBigInteger('sequence');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'event_id'], 'tno_tenant_event_unique');
            $table->index(['tenant_id', 'processed_at'], 'tno_tenant_processed_idx');
            $table->index(
                ['tenant_id', 'notifiable_id', 'stream_key', 'sequence'],
                'tno_tenant_notifiable_stream_sequence_idx',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('tenant_id');
            $table->foreignId('notifiable_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_id');
            $table->string('event_type', 120);
            $table->unsignedInteger('version')->default(1);
            $table->json('payload')->nullable();
            $table->string('stream_key', 190);
            $table->unsignedBigInteger('sequence');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'notifiable_id', 'event_id'], 'notifications_tenant_user_event_unique');
            $table->index(
                ['tenant_id', 'notifiable_id', 'is_read', 'sequence'],
                'notifications_tenant_user_read_sequence_idx',
            );
            $table->index(['tenant_id', 'stream_key', 'sequence'], 'notifications_tenant_stream_sequence_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tenant_notification_outbox');
        Schema::dropIfExists('tenant_notification_stream_sequences');
        Schema::dropIfExists('tenant_user_realtime_epochs');
    }
};
