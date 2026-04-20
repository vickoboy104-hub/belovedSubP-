<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'flutterwave_bvn')) {
                $table->text('flutterwave_bvn')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('users', 'flutterwave_nin')) {
                $table->text('flutterwave_nin')->nullable()->after('flutterwave_bvn');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('users', 'flutterwave_bvn')) {
                $columns[] = 'flutterwave_bvn';
            }

            if (Schema::hasColumn('users', 'flutterwave_nin')) {
                $columns[] = 'flutterwave_nin';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
