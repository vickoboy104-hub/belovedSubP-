<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('support_tickets', 'user_last_read_at')) {
                $table->timestamp('user_last_read_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('support_tickets', 'admin_last_read_at')) {
                $table->timestamp('admin_last_read_at')->nullable()->after('user_last_read_at');
            }
            if (!Schema::hasColumn('support_tickets', 'last_message_at')) {
                $table->timestamp('last_message_at')->nullable()->after('admin_last_read_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('support_tickets', 'last_message_at')) {
                $table->dropColumn('last_message_at');
            }
            if (Schema::hasColumn('support_tickets', 'admin_last_read_at')) {
                $table->dropColumn('admin_last_read_at');
            }
            if (Schema::hasColumn('support_tickets', 'user_last_read_at')) {
                $table->dropColumn('user_last_read_at');
            }
        });
    }
};

