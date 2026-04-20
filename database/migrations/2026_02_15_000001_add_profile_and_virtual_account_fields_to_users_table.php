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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 20)->nullable();

            $table->string('paystack_customer_code')->nullable();
            $table->string('paystack_customer_id')->nullable();
            $table->string('paystack_dva_bank')->nullable();
            $table->string('paystack_dva_account_name')->nullable();
            $table->string('paystack_dva_account_number')->nullable();
            $table->timestamp('paystack_dva_assigned_at')->nullable();
            $table->json('paystack_dva_metadata')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->index('paystack_customer_code');
            $table->index('paystack_dva_account_number');
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['paystack_customer_code']);
            $table->dropIndex(['paystack_dva_account_number']);
            $table->dropIndex(['phone']);

            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'paystack_customer_code',
                'paystack_customer_id',
                'paystack_dva_bank',
                'paystack_dva_account_name',
                'paystack_dva_account_number',
                'paystack_dva_assigned_at',
                'paystack_dva_metadata',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
