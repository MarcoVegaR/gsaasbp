<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('db_connection');
            $table->timestamp('status_changed_at')->nullable()->after('status');

            $table->index('status', 'tenants_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('tenants_status_idx');
            $table->dropColumn(['status', 'status_changed_at']);
        });
    }
};
