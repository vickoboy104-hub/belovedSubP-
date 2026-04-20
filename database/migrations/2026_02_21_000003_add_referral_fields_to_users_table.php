<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 32)->nullable()->unique()->after('discount_percent');
            $table->foreignId('referred_by_user_id')
                ->nullable()
                ->after('referral_code')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('referral_qualified_at')->nullable()->after('referred_by_user_id');
            $table->bigInteger('referral_earnings_balance')->default(0)->after('referral_qualified_at');
            $table->bigInteger('referral_earnings_total')->default(0)->after('referral_earnings_balance');
            $table->bigInteger('referral_earnings_withdrawn')->default(0)->after('referral_earnings_total');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn([
                'referral_code',
                'referral_qualified_at',
                'referral_earnings_balance',
                'referral_earnings_total',
                'referral_earnings_withdrawn',
            ]);
        });
    }
};
