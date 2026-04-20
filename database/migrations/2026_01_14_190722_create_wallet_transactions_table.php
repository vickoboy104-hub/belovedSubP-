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
    Schema::create('wallet_transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('wallet_id')->constrained()->onDelete('cascade');

        $table->enum('type', ['credit', 'debit']);
        $table->bigInteger('amount'); // in kobo
        $table->string('reference')->unique(); // paystack ref or internal ref
        $table->enum('status', ['pending', 'success', 'failed'])->default('pending');

        $table->string('channel')->nullable(); // paystack, purchase, refund
        $table->string('description')->nullable();

        $table->json('meta')->nullable(); // store raw provider response, etc.

        $table->timestamps();
    });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
