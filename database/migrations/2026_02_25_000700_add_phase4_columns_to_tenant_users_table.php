<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->timestamp('membership_revoked_at')->nullable()->after('is_banned');
            $table->string('membership_status')->default('active')->after('membership_revoked_at');

            $table->index(['tenant_id', 'membership_status'], 'tenant_users_tenant_membership_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropIndex('tenant_users_tenant_membership_status_idx');
            $table->dropColumn(['membership_revoked_at', 'membership_status']);
        });
    }
};
