<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's transfer fails at the gateway, the support desk settles it by hand
 * the same afternoon, and a week later Flutterwave finally reports that very charge
 * as successful. Nothing in the ledger connects the two facts: the manual credit
 * carries no gateway identity, because no gateway was involved, and the duplicate
 * guard can only compare money it has already banked. So the arrival passes every
 * check and the customer is paid twice.
 *
 * This table is that connection, and it is only ever written by a human who has
 * read both records. A relationship between a charge and a manual credit can never
 * be inferred from a matching amount, a matching customer or a matching account
 * number - a permanent virtual account reuses its reference on every transfer into
 * it, so any of those "matches" is as likely to be a genuine new deposit as to be
 * the old one, and guessing wrong either doubles a credit or holds money that is
 * owed. The admin states the link, names the charge by an identifier Flutterwave
 * gives to one individual charge, and signs it with a reason.
 *
 * Keyed on the charge token, not on the wallet: the claim being made is "this
 * specific money has already been paid out", which is true wherever it lands. The
 * unique index is what stops the same charge being declared twice, and a retracted
 * row stays with its outcome and its note so the declaration remains reconstructable
 * after a correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_settlement_links')) {
            return;
        }

        Schema::create('wallet_settlement_links', function (Blueprint $table) {
            $table->id();

            // charge_id = the charge's own `id`, flw_ref = the charge's `flw_ref`.
            // Both name one individual charge; `reference` and `tx_ref` do not, so
            // they cannot be used as the key.
            $table->string('charge_token', 191)->unique();
            $table->string('charge_token_kind', 16);

            // Kept for the human reading the screen, never used to match money.
            $table->string('tx_ref', 191)->nullable();

            $table->unsignedBigInteger('wallet_id')->index();

            // The admin credit that already paid this customer: a row of type
            // credit from either manual channel. Money is never moved here.
            $table->unsignedBigInteger('manual_transaction_id')->index();
            $table->unsignedBigInteger('amount_kobo')->default(0);

            $table->string('status', 12)->default('active')->index();
            $table->string('reason', 500);
            $table->unsignedBigInteger('declared_by_user_id');
            $table->timestamp('declared_at')->nullable();

            $table->unsignedBigInteger('retracted_by_user_id')->nullable();
            $table->timestamp('retracted_at')->nullable();
            $table->string('retraction_note', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_settlement_links');
    }
};
