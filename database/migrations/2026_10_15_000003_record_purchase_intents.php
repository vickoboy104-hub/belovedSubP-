<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two things are recorded about an intention. The claim table is the lock: one
     * customer may hold one identity once, and the database, not the browser, says so.
     * The order carries the same identity so the earlier purchase can be named when a
     * repeat arrives, and so a receipt can be traced back to the click that made it.
     *
     * Old orders keep a null identity. A null never collides, so no history is refused
     * and nothing has to invent an intention nobody recorded.
     */
    public function up(): void
    {
        Schema::create('purchase_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 64);
            $table->timestamps();

            $table->unique(['user_id', 'token'], 'purchase_intents_user_token_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('intent_token', 64)->nullable()->after('user_id');
            $table->index(['user_id', 'intent_token'], 'orders_user_intent_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_intent_index');
            $table->dropColumn('intent_token');
        });

        Schema::dropIfExists('purchase_intents');
    }
};
