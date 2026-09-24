<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('gsubz');
            $table->string('service_slug');
            $table->string('provider_service_id')->nullable();
            $table->string('plan_id');
            $table->string('plan_name')->nullable();
            $table->decimal('provider_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->boolean('selling_price_is_custom')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('raw_plan')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'service_slug', 'plan_id'], 'provider_plan_prices_unique_plan');
            $table->index(['provider', 'service_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_plan_prices');
    }
};
