<?php

namespace Tests\Feature;

use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletDepositSync;
use App\Services\WalletFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A verified bank transfer proves that money arrived. It does not prove who sent
 * it, and this site resolves that question with first-match lookups on a column
 * that is not unique: an account number can be recorded against two customers at
 * once, because Flutterwave reissues numbers and an admin can type one onto any
 * account.
 *
 * Before this file's guard, one such transfer was paid to whichever lookup
 * answered first. That is not a duplicate - the duplicate is closed elsewhere - it
 * is a wrong payment: one customer's balance up, the other still owed, and nothing
 * on either side saying the money was ever questionable.
 *
 * The rule these tests hold is therefore narrower and stronger than idempotency:
 * money may only move when one customer can be proved to own it. Anything else is
 * written down whole, with the candidates named, and paid by a human who can read
 * the transfer.
 */
class FlutterwaveDepositAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_ACCOUNT = '07777777777';
    private const OTHER_ACCOUNT = '06666666666';

    /** @var list<array<string, mixed>> */
    private array $listCharges = [];

    /** @var array<string, array<string, mixed>> */
    private array $verifiableCharges = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

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

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
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
    private function deliverWebhook(array $charge)
    {
        return $this->postJson('/wallet/flutterwave/webhook-v2', [
            'event' => 'charge.completed',
            'data' => $charge,
        ], ['verif-hash' => 'test-verif-hash']);
    }

    /** @param array<string, mixed> $overrides */
    private function fundingRequest(User $user, string $reference, array $overrides = []): WalletTransaction
    {
        return WalletTransaction::query()->create(array_merge([
            'wallet_id' => $user->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'reference' => $reference,
            'status' => 'pending',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Waiting for a bank transfer',
            'meta' => ['virtual_account_number' => self::SHARED_ACCOUNT],
        ], $overrides));
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function creditedKobo(): int
    {
        return (int) WalletTransaction::query()
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('meta->credited_kobo');
    }

    public function test_a_transfer_into_a_number_two_customers_hold_credits_nobody(): void
    {
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();

        $this->assertSame(0, $this->balance($alpha), 'A shared account number paid the customer the lookup found first.');
        $this->assertSame(0, $this->balance($beta));
        $this->assertSame(0, $this->creditedKobo());
        $this->assertSame(0, WalletTransaction::query()->where('status', 'success')->count());

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('shared_account_number', $held->reason);
        $this->assertSame('held', $held->status);
        $this->assertSame(300_000, (int) $held->amount_kobo);
        $this->assertSame(self::SHARED_ACCOUNT, $held->virtual_account_number);
        $this->assertSame('777', (string) $held->flutterwave_charge_id);
        $this->assertSame(
            [$alpha->id, $beta->id],
            array_map(intval(...), $held->holder_user_ids),
            'The held record does not name every customer this transfer could belong to.'
        );

        // Everything a human needs to check the payment at the bank is the payload
        // Flutterwave verified, kept whole rather than summarised.
        $this->assertSame('FLW-MOCK-777', $held->payload['flw_ref']);
        $this->assertSame('payer@belovedsubp.com', $held->payload['customer']['email']);
    }

    public function test_the_webhook_and_the_scheduled_sweep_competing_for_one_transfer_hold_it_once(): void
    {
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->fundingRequest($beta, 'FLW_VA_BETA_REQUEST');

        $this->verifiableCharges = [777 => $this->charge()];
        $this->listCharges = [['id' => 777, 'tx_ref' => 'FLW_VA_UNKNOWN']];

        $this->deliverWebhook($this->charge())->assertOk();

        // The five-minute sweep asks on both customers' behalf, in id order, so the
        // second arrival path is a scheduled job the site cannot stop.
        $this->app->make(WalletDepositSync::class)->sweep(10);

        $this->assertSame(0, $this->balance($alpha));
        $this->assertSame(0, $this->balance($beta));
        $this->assertSame(0, $this->creditedKobo());
        $this->assertSame(
            1,
            FlutterwaveHeldDeposit::query()->count(),
            'Two arrival paths asking about one unattributable transfer left two records for support to work through.'
        );
        $this->assertSame(
            0,
            DB::table('flutterwave_charge_claims')->count(),
            'Holding a deposit took its global claim, so an admin could never pay it.'
        );
    }

    public function test_a_funding_request_that_names_its_own_customer_is_paid_although_the_number_is_shared(): void
    {
        // The reference on a transfer is minted here, so it identifies a customer in
        // a way an account number cannot. A one-time account that two records happen
        // to share must not freeze the deposit its own request is waiting for.
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $request = $this->fundingRequest($alpha, 'FLW_VA_ALPHA_REQUEST');

        $this->verifiableCharges = [777 => $this->charge(['tx_ref' => 'FLW_VA_ALPHA_REQUEST'])];
        $this->deliverWebhook($this->charge(['tx_ref' => 'FLW_VA_ALPHA_REQUEST']))->assertOk();

        $this->assertSame(295_000, $this->balance($alpha));
        $this->assertSame(0, $this->balance($beta));
        $this->assertSame(0, FlutterwaveHeldDeposit::query()->count());
        $this->assertSame('success', $request->fresh()->status, 'The customer is still told to wait for money they got.');
    }

    public function test_a_transfer_into_a_number_only_somebody_else_holds_is_held_not_paid_to_the_wallet_it_reached(): void
    {
        // Flutterwave reissues an account number a customer stopped using, so a
        // first-match lookup can hand the next holder's transfer to the wallet that
        // number used to belong to. One holder, and it is not this wallet's owner:
        // that is not a choice between two customers, it is a payment to the wrong
        // one, and it used to happen silently.
        $previousHolder = $this->member();
        $realOwner = $this->member(['virtual_account_number' => self::OTHER_ACCOUNT]);

        $credited = app(WalletFundingService::class)->creditFlutterwaveCharge(
            $previousHolder->wallet,
            $this->charge(['account_number' => self::OTHER_ACCOUNT]),
            'flutterwave_virtual_account',
            'deposit_check'
        );

        $this->assertSame(0, $credited);
        $this->assertSame(0, $this->balance($previousHolder));
        $this->assertSame(0, $this->balance($realOwner));

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('wrong_wallet', $held->reason);
        $this->assertSame([$realOwner->id], array_map(intval(...), $held->holder_user_ids));
    }

    public function test_repeated_transfers_into_a_number_one_customer_holds_are_credited_as_usual(): void
    {
        $funding = app(WalletFundingService::class);
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        // A permanent account exists to be paid into again and again. Making a shared
        // number safe must not cost its only owner the second, third and fourth
        // genuine deposit.
        $this->assertSame(295_000, $funding->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(['id' => 777, 'flw_ref' => 'FLW-MOCK-777']),
            'flutterwave_virtual_account',
            'webhook'
        ));

        $this->assertSame(145_000, $funding->creditFlutterwaveCharge(
            $customer->fresh()->wallet,
            $this->charge(['id' => 888, 'flw_ref' => 'FLW-MOCK-888', 'charged_amount' => 1500.00, 'amount' => 1500.00]),
            'flutterwave_virtual_account',
            'deposit_check'
        ));

        $this->assertSame(440_000, $this->balance($customer));
        $this->assertSame(0, FlutterwaveHeldDeposit::query()->count());
    }

    public function test_an_admin_naming_the_customer_credits_that_customer_and_records_the_decision(): void
    {
        $admin = $this->admin();
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();

        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $beta->id,
                'note' => 'The payer confirmed by email that this was their transfer, and the bank statement matches.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->balance($alpha));
        $this->assertSame(295_000, $this->balance($beta));

        $held->refresh();
        $this->assertSame('credited', $held->status);
        $this->assertSame($admin->id, (int) $held->resolved_by_user_id);
        $this->assertSame($beta->wallet->id, (int) $held->resolved_wallet_id);
        $this->assertNotNull($held->resolved_at);
        $this->assertStringContainsString('bank statement', $held->resolution_note);

        // The resolution is an ordinary credit: same fee, same ledger row, same
        // notification, and the money is not taken a second time by the replay that
        // is still going to arrive.
        $this->assertSame(1, WalletTransaction::query()->where('status', 'success')->count());
        $this->assertSame(2, DB::table('flutterwave_charge_claims')->count());

        $this->deliverWebhook($this->charge())->assertOk();

        $this->assertSame(295_000, $this->balance($beta), 'A resolved deposit was paid again by the replay behind it.');
        $this->assertSame(0, $this->balance($alpha));
        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count());
    }

    public function test_a_held_transfer_is_not_credited_without_a_reason_and_only_to_a_customer_it_could_be(): void
    {
        $admin = $this->admin();
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $beta = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $stranger = $this->member(['virtual_account_number' => self::OTHER_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();
        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), ['user_id' => $beta->id])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $stranger->id,
                'note' => 'Nobody on this transfer matches, so pay somebody else entirely.',
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, $this->balance($alpha));
        $this->assertSame(0, $this->balance($beta));
        $this->assertSame(0, $this->balance($stranger));
        $this->assertSame('held', $held->fresh()->status);
    }

    public function test_an_admin_can_close_a_held_transfer_as_not_ours_and_nobody_is_paid(): void
    {
        $admin = $this->admin();
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();
        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.dismiss', $held), [
                'note' => 'Flutterwave confirms the transfer was reversed by the sending bank the same day.',
            ])
            ->assertSessionHasNoErrors();

        $held->refresh();
        $this->assertSame('dismissed', $held->status);
        $this->assertSame(0, $this->creditedKobo());
        $this->assertNull($held->resolved_wallet_id, 'A deposit closed as not ours still points at a wallet.');

        // The record survives: a payment decision that is reversed should still be
        // readable next month, which is why this is a status change and not a delete.
        $this->assertStringContainsString('reversed', $held->resolution_note);

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $alpha->id,
                'note' => 'Changed my mind after the bank confirmed the reversal.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->creditedKobo());
        $this->assertSame(0, $this->balance($alpha));
    }

    public function test_the_held_queue_is_visible_to_an_admin_and_closed_to_everybody_else(): void
    {
        $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $member = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();

        $page = $this->actingAs($this->admin())
            ->get(route('admin.wallet.deposits.held'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::SHARED_ACCOUNT, $page);
        $this->assertStringContainsString('More than one customer holds this account number', $page);

        // The admin gate answers 403 whether or not a session exists, so the page
        // cannot be probed for which transfers are held.
        $this->actingAs($member)->get(route('admin.wallet.deposits.held'))->assertForbidden();
        $this->get(route('admin.wallet.deposits.held'))->assertForbidden();

        $this->actingAs($member)
            ->post(route('admin.wallet.deposits.held.credit', FlutterwaveHeldDeposit::query()->sole()), [
                'user_id' => $member->id,
                'note' => 'pay me, I am sure it was mine',
            ])
            ->assertForbidden();
    }

    public function test_money_that_is_held_is_still_counted_as_outstanding_by_the_deposit_audit(): void
    {
        $alpha = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $request = $this->fundingRequest($alpha, 'FLW_VA_ALPHA_REQUEST', [
            'meta' => ['virtual_account_number' => self::SHARED_ACCOUNT],
        ]);

        $this->verifiableCharges = [777 => $this->charge()];
        $this->deliverWebhook($this->charge())->assertOk();

        // Holding the money must not silently discharge the promise made to the
        // customer: their request is still open, so the read-only audit still sees it.
        $this->assertSame('pending', $request->fresh()->status);

        Cache::forget('wallet-deposit-sync-button-'.$alpha->id);
        $this->actingAs($alpha->fresh())->post('/wallet/deposits/check');

        $this->assertSame(0, $this->balance($alpha));
        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count());
    }
}
