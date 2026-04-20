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
    Schema::create('orders', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->foreignId('service_id')->constrained()->onDelete('cascade');

        $table->string('customer_ref'); // phone number
        $table->bigInteger('amount'); // kobo

        $table->string('provider')->default('mock'); // mock now, gsubz later
        $table->string('provider_reference')->nullable(); // requestID

        $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending');
        $table->json('meta')->nullable();

        $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
