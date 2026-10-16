<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletFundingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One bank transfer must produce exactly one wallet credit - never twice, and
 * never once apiece in two different customers' wallets.
 *
 * The per-wallet check in WalletFundingService can only ever see the rows of the
 * wallet it was handed, while the wallet itself is picked by a chain of lookups
 * (the checkout reference, the virtual account number, the account number written
 * on an old funding request) that are not unique in this database. This suite asks
 * whether one verified Flutterwave charge can therefore be banked twice, and keeps
 * the answer provable rather than argued.
 *
 * The suite's counterpart, FlutterwaveDepositAttributionTest, covers what happens
 * when a charge cannot be banked twice but could still be banked into the wrong
 * wallet: nobody is paid and the transfer is held for an admin.
 */
class FlutterwaveChargeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_NUMBER = '01234567890';
    private const SHARED_ACCOUNT = '07777777777';

    /** @var list<array<string, mixed>> What GET /v3/transactions answers with. */
    private array $listCharges = [];

    /** @var array<string, array<string, mixed>> id or tx_ref => verify body. */
    private array $verifiableCharges = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'test-secret-key',
            'services.flutterwave.secret_hash' => 'test-verif-hash',
        ]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url((string) $request->url(), PHP_URL_PATH);

            if (preg_match('#^/v3/transactions/([^/]+)/verify$#', $path, $match)) {
                $charge = $this->verifiableCharges[urldecode($match[1])] ?? null;

                return $charge === null
                    ? Http::response(['status' => 'failed', 'message' => 'not found'], 404)
                    : Http::response(['status' => 'success', 'data' => $charge]);
            }

            if (str_ends_with($path, '/v3/transactions')) {
                return Http::response(['status' => 'success', 'data' => $this->listCharges]);
            }

            return Http::response(['status' => 'failed', 'message' => 'unexpected call'], 404);
        });
    }

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['email_verified_at' => now()])->save();

        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function charge(array $overrides = []): array
    {
        return array_merge([
            'id' => 777,
            'tx_ref' => 'FLW_VA_UNKNOWN',
            'flw_ref' => 'FLW-MOCK-777',
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => 3000.00,
            'amount' => 3000.00,
            'payment_type' => 'bank_transfer',
            'account_number' => self::SHARED_ACCOUNT,
            'created_at' => now()->toIso8601String(),
            'customer' => ['email' => 'payer@belovedsubp.com'],
        ], $overrides);
    }

    /** @param array<string, mixed> $charge */
    private function deliverWebhook(array $charge, string $verifHash = 'test-verif-hash')
    {
        return $this->postJson('/wallet/flutterwave/webhook-v2', [
            'event' => 'charge.completed',
            'data' => $charge,
        ], ['verif-hash' => $verifHash]);
    }

    /**
     * Run the shipping migration file again over whatever data the test just put
     * in the ledger, so the backfill is proved against real rows rather than
     * against an empty database.
     */
    private function runTheClaimsMigrationAgain(): void
    {
        Schema::drop('flutterwave_charge_claims');

        $this->runTheClaimsMigration();
    }

    private function runTheClaimsMigration(): void
    {
        $migration = require database_path('migrations/2026_10_16_000001_create_flutterwave_charge_claims_table.php');
        $migration->up();
    }

    /** Every naira credited across the whole ledger, so a second wallet cannot hide. */
    private function totalCreditedKobo(): int
    {
        return (int) WalletTransaction::query()
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('meta->credited_kobo');
    }

    public function test_the_same_charge_reported_twice_by_the_webhook_credits_once(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->verifiableCharges = [777 => $this->charge()];

        $this->deliverWebhook($this->charge())->assertOk();
        $this->deliverWebhook($this->charge())->assertOk();

        $this->assertSame(
            295_000,
            (int) $customer->fresh()->wallet->balance,
            'A webhook retry took the same deposit a second time.'
        );
        $this->assertSame(295_000, $this->totalCreditedKobo());
    }

    public function test_a_webhook_and_the_reconciliation_that_follows_it_credit_once(): void
    {
        $customer = $this->member();
        $request = WalletTransaction::query()->create([
            'wallet_id' => $customer->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'reference' => 'FLW_VA_REQUEST',
            'status' => 'pending',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Waiting for a bank transfer',
            'meta' => ['virtual_account_number' => self::SHARED_ACCOUNT],
        ]);

        $this->verifiableCharges = [777 => $this->charge(['tx_ref' => 'FLW_VA_REQUEST'])];
        $this->listCharges = [['id' => 777, 'tx_ref' => 'FLW_VA_REQUEST']];

        // Two different windows, because both checks throttle per customer and a
        // webhook does not consume the customer's own deposit check.
        $this->deliverWebhook($this->charge(['tx_ref' => 'FLW_VA_REQUEST']))->assertOk();
        $this->assertSame(295_000, (int) $customer->fresh()->wallet->balance);

        Cache::forget('wallet-deposit-sync-button-'.$customer->id);
        $this->actingAs($customer->fresh())->post('/wallet/deposits/check');

        $this->assertSame(
            295_000,
            (int) $customer->fresh()->wallet->balance,
            'The deposit sweep banked a transfer the webhook had already credited.'
        );
        $this->assertSame(295_000, $this->totalCreditedKobo());
        $this->assertSame('success', $request->fresh()->status);
    }

    public function test_one_verified_charge_cannot_credit_two_different_wallets(): void
    {
        $funding = app(WalletFundingService::class);

        // The charge names A as its own funding request and B as the account it
        // landed in, so each arrival path resolves a different customer and each is
        // unambiguous on its own terms. The webhook takes A; the deposit sweep for
        // B then asks about the very same money.
        $first = $this->member();
        $second = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        WalletTransaction::query()->create([
            'wallet_id' => $first->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'reference' => 'FLW_VA_ALPHA_REQUEST',
            'status' => 'pending',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Waiting for a bank transfer',
            'meta' => ['virtual_account_number' => '09999999999'],
        ]);

        $charge = $this->charge([
            'tx_ref' => 'FLW_VA_ALPHA_REQUEST',
            'account_number' => self::SHARED_ACCOUNT,
        ]);

        $this->assertSame(295_000, $funding->creditFlutterwaveCharge(
            $first->wallet,
            $charge,
            'flutterwave_virtual_account',
            'webhook'
        ));

        // The charge identity is already claimed in the database by the first
        // wallet, so the second one is refused even though nothing in its own
        // ledger history mentioned this transfer.
        $this->assertSame(0, $funding->creditFlutterwaveCharge(
            $second->fresh()->wallet,
            $charge,
            'flutterwave_virtual_account',
            'deposit_check'
        ), 'The same Flutterwave charge funded two customers out of one transfer.');

        $this->assertSame(0, (int) $second->fresh()->wallet->balance);
        $this->assertSame(295_000, $this->totalCreditedKobo());
        $this->assertSame(1, WalletTransaction::query()->where('status', 'success')->count());

        // Losing the race must not leave a half-claim behind: a token recorded for
        // the wallet that was refused would lock the deposit out of the wallet that
        // genuinely holds it, for good.
        $claims = DB::table('flutterwave_charge_claims')->orderBy('charge_token')->get();
        $this->assertCount(2, $claims);
        $this->assertSame(
            [$first->wallet->id, $first->wallet->id],
            $claims->pluck('wallet_id')->all(),
            'A refused charge left a claim pointing at the wallet it never funded.'
        );
    }

    public function test_a_deposit_banked_before_this_guard_existed_still_cannot_be_paid_twice(): void
    {
        $funding = app(WalletFundingService::class);

        // Money credited last week has a ledger row but, until this migration's
        // backfill ran, no claim. Without that backfill a transfer paid out before
        // the deploy could be paid out again to somebody else after it.
        $earlier = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        WalletTransaction::query()->create([
            'wallet_id' => $earlier->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'reference' => 'FLW_VA_BANKED_LAST_WEEK',
            'status' => 'success',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Wallet funding',
            'meta' => ['flutterwave_charge_id' => '555', 'flw_ref' => 'FLW-MOCK-555', 'credited_kobo' => 295_000],
        ]);
        $earlier->wallet->update(['balance' => 295_000]);

        $later = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->runTheClaimsMigrationAgain();

        $this->assertSame(
            2,
            DB::table('flutterwave_charge_claims')->whereIn('charge_token', ['555', 'FLW-MOCK-555'])->count(),
            'The migration left deposits already banked unclaimed.'
        );

        $this->assertSame(0, $funding->creditFlutterwaveCharge(
            $later->wallet,
            $this->charge(['id' => 555, 'flw_ref' => 'FLW-MOCK-555']),
            'flutterwave_virtual_account',
            'webhook'
        ));
        $this->assertSame(0, (int) $later->fresh()->wallet->balance);
        $this->assertSame(
            295_000,
            (int) $earlier->fresh()->wallet->balance,
            'Rebuilding the claims table disturbed a balance that was already banked.'
        );
    }

    public function test_rebuilding_the_claims_table_leaves_every_ledger_row_alone(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        WalletTransaction::query()->create([
            'wallet_id' => $customer->wallet->id,
            'type' => 'credit',
            'amount' => 500_000,
            'reference' => 'FLW_VA_OLD_CREDIT',
            'status' => 'success',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Wallet funding',
            'meta' => ['flutterwave_charge_id' => '601', 'credited_kobo' => 495_000],
        ]);

        $before = DB::table('flutterwave_charge_claims')->count();

        $this->runTheClaimsMigrationAgain();

        $this->assertSame($before + 1, DB::table('flutterwave_charge_claims')->count());
        $this->assertSame(1, WalletTransaction::query()->where('reference', 'FLW_VA_OLD_CREDIT')->count());
        $this->assertSame(
            'success',
            (string) WalletTransaction::query()->where('reference', 'FLW_VA_OLD_CREDIT')->value('status')
        );
        $this->assertSame(0, (int) $customer->fresh()->wallet->balance, 'Nothing was banked here, only claimed.');
    }

    public function test_a_transfer_that_could_belong_to_either_customer_credits_neither(): void
    {
        // A virtual account number is indexed, not unique: Flutterwave hands a
        // replaced number back out, and an admin can type one onto any customer.
        // Choosing between two holders is not a question the ledger can answer, so
        // the money is held instead of paid to whichever lookup returned first.
        // Who is actually paid is FlutterwaveDepositAttributionTest's business.
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->listCharges = [['id' => 777, 'tx_ref' => 'FLW_VA_BETA_REQUEST']];

        $this->deliverWebhook($this->charge())->assertOk();

        Cache::forget('wallet-deposit-sync-button-'.$beta->id);
        $this->actingAs($beta->fresh())->post('/wallet/deposits/check');

        $this->assertSame(0, (int) $alpha->fresh()->wallet->balance);
        $this->assertSame(0, (int) $beta->fresh()->wallet->balance);
        $this->assertSame(0, $this->totalCreditedKobo());
        $this->assertSame(0, WalletTransaction::query()->where('status', 'success')->count());
        $this->assertSame(
            0,
            DB::table('flutterwave_charge_claims')->count(),
            'A deposit that paid nobody still consumed its global claim, locking the money out of the wallet that owns it.'
        );
        $this->assertSame(
            1,
            DB::table('flutterwave_held_deposits')->count(),
            'Two paths asked about one unattributable transfer and recorded it twice.'
        );
    }

    public function test_two_real_transfers_into_one_virtual_account_are_both_credited(): void
    {
        $funding = app(WalletFundingService::class);
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->assertSame(295_000, $funding->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(['id' => 777, 'flw_ref' => 'FLW-MOCK-777']),
            'flutterwave_virtual_account',
            'webhook'
        ));

        // A permanent account is meant to be paid into again and again. A global
        // guard keyed on anything other than the charge itself would throw this
        // second, genuine deposit away as a replay of the first.
        $this->assertSame(145_000, $funding->creditFlutterwaveCharge(
            $customer->fresh()->wallet,
            $this->charge(['id' => 888, 'flw_ref' => 'FLW-MOCK-888', 'charged_amount' => 1500.00, 'amount' => 1500.00]),
            'flutterwave_virtual_account',
            'deposit_check'
        ));

        $this->assertSame(440_000, (int) $customer->fresh()->wallet->balance);
        $this->assertSame(2, WalletTransaction::query()->where('status', 'success')->count());
    }

    public function test_a_charge_flutterwave_describes_only_by_its_gateway_reference_is_still_credited(): void
    {
        $funding = app(WalletFundingService::class);
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        // A payload with no charge id at all must not be refused for wanting an
        // identity it was never given; the guard may only ever be a duplicate
        // check, never a precondition for banking a customer's money.
        $credited = $funding->creditFlutterwaveCharge(
            $customer->wallet,
            ['id' => null, 'flw_ref' => null, 'tx_ref' => 'FLW_VA_UNKNOWN', 'account_number' => self::SHARED_ACCOUNT,
                'charged_amount' => 3000.00, 'amount' => 3000.00, 'status' => 'successful', 'currency' => 'NGN'],
            'flutterwave_virtual_account',
            'webhook'
        );

        $this->assertSame(295_000, $credited);
        $this->assertSame(295_000, (int) $customer->fresh()->wallet->balance);
    }

    public function test_a_webhook_without_the_site_key_credits_nothing(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->verifiableCharges = [777 => $this->charge()];

        $this->deliverWebhook($this->charge(), 'wrong-hash')->assertStatus(401);

        $this->assertSame(0, (int) $customer->fresh()->wallet->balance);
        $this->assertSame(0, $this->totalCreditedKobo());
    }

    public function test_a_guard_that_cannot_run_stops_the_request_instead_of_losing_the_deposit(): void
    {
        $funding = app(WalletFundingService::class);
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        // The unique index is the only reason this credit is safe to retry, so a
        // claims table that is missing or unreachable has to break the request.
        // Swallowing it would let the service answer "already paid out", log it at
        // info, and leave a customer's real money pending with nobody alerted.
        Schema::drop('flutterwave_charge_claims');

        $thrown = null;
        try {
            $funding->creditFlutterwaveCharge(
                $customer->wallet,
                $this->charge(),
                'flutterwave_virtual_account',
                'webhook'
            );
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->runTheClaimsMigration();

        $this->assertNotNull($thrown, 'The charge was taken with nowhere to record that it had been taken.');
        $this->assertStringContainsString('flutterwave_charge_claims', $thrown->getMessage());
        $this->assertSame(0, (int) $customer->fresh()->wallet->balance);
        $this->assertSame(0, $this->totalCreditedKobo());
    }
}
