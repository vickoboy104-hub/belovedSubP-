<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A verified Flutterwave transfer says which bank account it arrived in, and a
 * bank account number is not a person: the same number can be recorded against
 * two customers (Flutterwave reissues numbers, and an admin can type one onto any
 * account), and funding requests long closed out still name the number they were
 * given. Every arrival path here resolves a customer with a first-match lookup, so
 * a transfer can be banked into a wallet whose owner never sent it - once, to the
 * wrong person, with the right person still owed.
 *
 * Nothing in the ledger can hold that money, because crediting it is exactly what
 * the ledger row means. This table is where a deposit waits instead: the verified
 * charge recorded in full, no balance touched, and the candidates an admin has to
 * choose between named. One row per charge identity, so a webhook and a deposit
 * sweep asking about the same transfer produce one held deposit and not two.
 *
 * Additive and forward-only: no existing row is moved here, because a credit that
 * already happened cannot be un-paid by a migration, and pretending otherwise
 * would rewrite balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flutterwave_held_deposits')) {
            return;
        }

        Schema::create('flutterwave_held_deposits', function (Blueprint $table) {
            $table->id();

            // The charge this row is the sole record of. Unique, so the same
            // transfer reported through two paths is held once.
            $table->string('charge_token', 191)->unique();

            $table->string('flutterwave_charge_id', 191)->nullable();
            $table->string('flutterwave_id', 191)->nullable();
            $table->string('tx_ref', 191)->nullable();
            $table->string('virtual_account_number', 60)->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 8)->nullable();
            $table->string('channel', 60)->nullable();
            $table->string('arrived_via', 60);

            // Why nobody was paid: more than one customer matches, or the only
            // customer who matches is not the wallet this transfer was routed to.
            $table->string('reason', 60);
            $table->json('holder_user_ids')->nullable();
            $table->json('payload')->nullable();

            $table->string('status', 20)->default('held');
            $table->text('resolution_note')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->unsignedBigInteger('resolved_wallet_id')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('virtual_account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flutterwave_held_deposits');
    }
};
