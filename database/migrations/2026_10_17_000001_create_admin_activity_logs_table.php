<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin accounts can move a customer's money, hand out admin access, rewrite the
 * storefront, and answer a support chat. Each of those actions already writes a
 * row somewhere - into a ledger meta blob, an order history array, a log file -
 * but none of them can be read back as one question: what did this person do, in
 * what order, and was it allowed. When a wallet moves and nobody can say who
 * moved it, the money path is unauditable, so this table exists to make the
 * answer a lookup rather than a guess.
 *
 * Append-only by design. There is no updated_at and nothing here is ever edited
 * or removed by application code: a record that can be rewritten is not evidence,
 * and an admin who can hide their own history has no history. The write is meant
 * to commit inside the same transaction as the action it describes, so an action
 * that rolled back does not leave a phantom log entry, and a logged success
 * cannot describe an action that never persisted.
 *
 * Deliberately absent, not forgotten: passwords, API tokens, session ids,
 * encryption keys, OTP/PIN values, full NIN or BVN digits, card data and payment
 * credentials of any kind. A session is identified by a salted hash of its id,
 * which is enough to group one sitting's actions and useless for riding it. The
 * email an attacker typed into a failed sign-in is also not stored - an IP, a
 * timestamp and a user agent show a guessing attempt just as well, while storing
 * the address would fill this table with third parties' personal data.
 *
 * Additive and forward-only: no existing row is imported, because a hand-written
 * ledger entry from last month does not prove who typed it, and inventing an
 * author for it would be worse than an honest gap. History in this table starts
 * at the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }

        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();

            // One value per request, so a denial, its validation failure and the
            // eventual success can be read back as a single sitting.
            $table->string('correlation_id', 64)->index();

            // Set when a non-admin or a signed-out visitor reaches an admin route:
            // the attempt itself is the finding, and there is no account to name.
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('admin_name', 255)->nullable();

            // wallet / customer / order / platform / auth / support / broadcast /
            // transaction_review, plus the machine action inside it.
            $table->string('category', 40)->index();
            $table->string('action', 100);
            $table->string('description', 1000);

            // The record the action acted on, and the payment reference it relates
            // to when there is one. This site's own references, never a gateway
            // secret.
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('reference', 191)->nullable();

            $table->string('result', 12);
            $table->string('reason', 500)->nullable();

            // Before / after values, already reduced to non-sensitive fields.
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();

            // Salted, truncated hash of the PHP session id: groups actions by
            // sitting without storing a usable credential.
            $table->string('session_hash', 32)->nullable();
            $table->string('user_agent', 255)->nullable();

            // One action, one row. Guards against a retried request, a double
            // submit or a re-fired auth event stacking up duplicate history.
            $table->string('dedupe_key', 64)->unique();

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
