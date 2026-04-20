<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'profit')) {
            Schema::table('orders', function (Blueprint $table) {
                // store in kobo like amount
                $table->unsignedBigInteger('profit')->default(0)->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'profit')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('profit');
            });
        }
    }
};
