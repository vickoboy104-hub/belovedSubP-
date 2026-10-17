<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money arriving in a wallet is currently only ever compared against the rows of
 * the wallet being credited, while the wallet itself is chosen by lookups that are
 * not unique in this database (an account number can be held by two customers, and
 * a webhook and the deposit sweep each pick their own). One verified Flutterwave
 * charge can therefore be paid out twice - once to each of two customers - and no
 * existing constraint says otherwise.
 *
 * This table is that statement: one row per charge identity, taken once, at the
 * moment a credit is banked. It is a new table rather than a unique index on
 * wallet_transactions, because the ledger already holds rows whose references
 * legitimately repeat (a permanent account is paid into again and again) and an
 * index added blind would either fail to build or refuse real deposits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flutterwave_charge_claims')) {
            return;
        }

        Schema::create('flutterwave_charge_claims', function (Blueprint $table) {
            $table->id();
            $table->string('charge_token', 191)->unique();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->string('reference', 191)->nullable();
            $table->unsignedBigInteger('amount_kobo')->nullable();
            $table->string('arrived_via', 60)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('wallet_id');
        });

        $this->backfillExistingCredits();
    }

    public function down(): void
    {
        Schema::dropIfExists('flutterwave_charge_claims');
    }

    /**
     * Every deposit already banked is claimed before this guard goes live, so a
     * transfer credited last month cannot be credited to somebody else today.
     * Existing duplicates in the ledger - the very thing this closes - are kept
     * exactly as they are and claimed once, which is the first row's wallet.
     */
    private function backfillExistingCredits(): void
    {
        $seen = [];
        $pending = [];

        DB::table('wallet_transactions')
            ->where('type', 'credit')
            ->where('status', 'success')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$seen, &$pending): void {
                foreach ($rows as $row) {
                    foreach ($this->chargeTokens($this->decode($row->meta ?? null)) as $token) {
                        if (isset($seen[$token])) {
                            continue;
                        }
                        $seen[$token] = true;

                        $pending[] = [
                            'charge_token' => $token,
                            'wallet_id' => $row->wallet_id ?? null,
                            'reference' => $row->reference ?? null,
                            'amount_kobo' => $row->amount ?? null,
                            'arrived_via' => $this->decode($row->meta ?? null)['arrived_via'] ?? null,
                            'created_at' => $row->created_at ?? now(),
                        ];
                    }
                }
            });

        foreach (array_chunk($pending, 200) as $chunk) {
            DB::table('flutterwave_charge_claims')->insert($chunk);
        }
    }

    /**
     * The identifiers Flutterwave gives an individual charge. Deliberately not the
     * customer's own reference or the account number: those repeat by design.
     *
     * @return list<string>
     */
    private function chargeTokens(array $meta): array
    {
        $payload = (array) ($meta['flutterwave_payload'] ?? []);
        $tokens = [];

        foreach ([
            $meta['flutterwave_charge_id'] ?? null,
            $payload['id'] ?? null,
            $meta['flutterwave_id'] ?? null,
            $meta['flw_ref'] ?? null,
            $payload['flw_ref'] ?? null,
        ] as $token) {
            $token = trim((string) $token);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function decode(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
