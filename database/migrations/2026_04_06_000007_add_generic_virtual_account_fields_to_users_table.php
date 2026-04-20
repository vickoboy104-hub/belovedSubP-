<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'virtual_account_provider')) {
                $table->string('virtual_account_provider')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'virtual_account_bank')) {
                $table->string('virtual_account_bank')->nullable()->after('virtual_account_provider');
            }
            if (!Schema::hasColumn('users', 'virtual_account_name')) {
                $table->string('virtual_account_name')->nullable()->after('virtual_account_bank');
            }
            if (!Schema::hasColumn('users', 'virtual_account_number')) {
                $table->string('virtual_account_number')->nullable()->after('virtual_account_name');
            }
            if (!Schema::hasColumn('users', 'virtual_account_assigned_at')) {
                $table->timestamp('virtual_account_assigned_at')->nullable()->after('virtual_account_number');
            }
            if (!Schema::hasColumn('users', 'virtual_account_metadata')) {
                $table->json('virtual_account_metadata')->nullable()->after('virtual_account_assigned_at');
            }

            $table->index('virtual_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['virtual_account_number']);

            $table->dropColumn([
                'virtual_account_provider',
                'virtual_account_bank',
                'virtual_account_name',
                'virtual_account_number',
                'virtual_account_assigned_at',
                'virtual_account_metadata',
            ]);
        });
    }
};
