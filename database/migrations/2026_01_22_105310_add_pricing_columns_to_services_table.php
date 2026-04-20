<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Pricing + status controls for admin adjustments
            if (!Schema::hasColumn('services', 'base_price')) {
                $table->decimal('base_price', 10, 2)->default(0)->after('name');
            }
            if (!Schema::hasColumn('services', 'selling_price')) {
                $table->decimal('selling_price', 10, 2)->default(0)->after('base_price');
            }
            if (!Schema::hasColumn('services', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('selling_price');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('services', 'selling_price')) {
                $table->dropColumn('selling_price');
            }
            if (Schema::hasColumn('services', 'base_price')) {
                $table->dropColumn('base_price');
            }
        });
    }
};
