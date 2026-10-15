<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A customer's paper is held on disk before the wallet is touched, and it is
     * moved against the order afterwards. In between there is a moment where the
     * money has been taken and the order exists, and only the browser was keeping
     * track of where the documents were sitting. If the process dies in that
     * moment the customer holds a paid request with no document, the paper stays
     * in a holding folder nobody owns, and nothing on the system can see it.
     *
     * This table is that missing memory, written in the same transaction that
     * takes the money: which order paid, which folder holds its paper, and what
     * is in the folder. It says nothing about whether the movement worked, only
     * what still has to be moved.
     */
    public function up(): void
    {
        Schema::create('document_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('token', 32);
            $table->json('manifest');
            $table->string('state', 16)->default('awaiting');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['state', 'created_at'], 'document_filings_state_created_index');
            $table->index('token', 'document_filings_token_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_filings');
    }
};
