<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nin_verification_caches', function (Blueprint $table) {
            $table->id();
            $table->string('lookup_nin')->nullable()->index();
            $table->string('lookup_phone')->nullable()->index();
            $table->string('lookup_demo')->nullable()->index();
            $table->string('resolved_nin')->nullable()->index();
            $table->string('resolved_phone')->nullable()->index();
            $table->string('source_lookup_type', 20)->nullable()->index();
            $table->json('source_payload')->nullable();
            $table->json('normalized_data')->nullable();
            $table->json('provider_data')->nullable();
            $table->foreignId('first_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('last_verified_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nin_verification_caches');
    }
};
