<?php

namespace Tests\Feature;

use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Customers complained that transfers arrived and their balances did not move. Some
 * of those deposits were genuinely ambiguous and are being handled by the owner by
 * hand; the rest were never ambiguous at all - they were thrown away. Three arrivals
 * used to end as a line in a log file and nothing else: a verified transfer no
 * lookup could attribute, a transfer that landed short of the funding request it
 * answered, and a transfer smaller than the funding fee. The first two left the
 * customer staring at a balance that never changed with no board anywhere saying the
 * money had been seen. The third was worse: it was written up as a successful credit
 * of zero kobo, so the ledger said the money was banked and the balance disagreed.
 *
 * These tests hold the corrected rule. Money that this site can prove arrived is
 * written down whole, with the reason, and paid to nobody until a person reads it -
 * and the record has to be finishable, which means an admin who names the customer
 * must be able to bank what actually landed.
 *
 * The other half of the promise, and the half that is easier to break by accident:
 * none of these holds may stand in front of a genuine new deposit. The last tests in
 * this file pay an ordinary, full-size funding request through and assert that the
 * board stays empty while doing it.
 */
class FlutterwaveDepositRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_ACCOUNT = '07777777777';
    private const OTHER_ACCOUNT = '06666666666';
    private const ORPHAN_ACCOUNT = '05555555555';

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
                return Http::response(['status' => 'success', 'data' => []]);
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
            'id' => 900,
            'tx_ref' => 'FLW_VA_UNKNOWN',
            'flw_ref' => 'FLW-MOCK-900',
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
        $this->verifiableCharges = [$charge['id'] => $charge] + $this->verifiableCharges;

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

    public function test_a_transfer_that_lands_short_of_what_the_customer_asked_for_is_written_down(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $request = $this->fundingRequest($customer, 'FLW_VA_SHORT');

        // ₦2,500 landed against a ₦3,000 request. Crediting the shortfall accepted
        // less than the customer agreed to; crediting the requested figure would bank
        // ₦500 that never arrived. Both disagree with the bank, so this used to return
        // zero and say nothing - which is exactly a deposit the customer thinks was
        // lost.
        $this->deliverWebhook($this->charge([
            'id' => 901,
            'flw_ref' => 'FLW-MOCK-901',
            'tx_ref' => 'FLW_VA_SHORT',
            'charged_amount' => 2500.00,
            'amount' => 2500.00,
        ]))->assertOk();

        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(0, $this->creditedKobo());
        $this->assertSame('pending', $request->fresh()->status);

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('amount_below_requested', $held->reason);
        $this->assertSame(250_000, (int) $held->amount_kobo);
        $this->assertSame('FLW_VA_SHORT', $held->tx_ref);
        $this->assertSame([$customer->id], array_map(intval(...), $held->holder_user_ids));
        $this->assertSame('FLW-MOCK-901', $held->payload['flw_ref']);

        // A hold an admin cannot release is a dead end, not a queue: taking the global
        // charge claim here would mean the credit button could never pay anybody.
        $this->assertSame(0, DB::table('flutterwave_charge_claims')->count());
    }

    public function test_an_admin_who_names_the_customer_banks_the_amount_that_actually_landed(): void
    {
        $admin = $this->admin();
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $request = $this->fundingRequest($customer, 'FLW_VA_SHORT');

        $charge = $this->charge([
            'id' => 901,
            'flw_ref' => 'FLW-MOCK-901',
            'tx_ref' => 'FLW_VA_SHORT',
            'charged_amount' => 2500.00,
            'amount' => 2500.00,
        ]);
        $this->deliverWebhook($charge)->assertOk();
        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $customer->id,
                'note' => 'The sending bank charged ₦500 against the transfer; the customer confirms ₦2,500 left them.',
            ])
            ->assertSessionHasNoErrors();

        // The fee is charged on what landed, and the row says what landed, so the
        // ledger and the bank statement end up telling the same story.
        $this->assertSame(245_000, $this->balance($customer));
        $request->refresh();
        $this->assertSame('success', $request->status);
        $this->assertSame(250_000, (int) $request->amount);
        $this->assertSame(245_000, (int) $request->meta['credited_kobo']);
        $this->assertSame('credited', $held->fresh()->status);

        $this->deliverWebhook($charge)->assertOk();

        $this->assertSame(245_000, $this->balance($customer), 'A released shortfall was paid again by the replay behind it.');
        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count());
    }

    public function test_a_transfer_smaller_than_the_funding_fee_is_recorded_instead_of_reported_as_banked(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        // ₦20 in, and the agreed funding fee is ₦50, so the correct credit is zero.
        // The bug was that zero was still written as a successful credit: money marked
        // banked that never reached a balance, and no board that mentioned it.
        $this->deliverWebhook($this->charge([
            'id' => 902,
            'flw_ref' => 'FLW-MOCK-902',
            'tx_ref' => 'FLW_VA_TINY',
            'charged_amount' => 20.00,
            'amount' => 20.00,
        ]))->assertOk();

        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(
            0,
            WalletTransaction::query()->where('status', 'success')->count(),
            'A transfer that could credit nothing was still written up as a successful deposit.'
        );

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('amount_below_fee', $held->reason);
        $this->assertSame(2_000, (int) $held->amount_kobo);
        $this->assertSame([$customer->id], array_map(intval(...), $held->holder_user_ids));
    }

    public function test_a_transfer_below_the_fee_credits_nobody_even_when_an_admin_releases_it(): void
    {
        $admin = $this->admin();
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        $this->deliverWebhook($this->charge([
            'id' => 902,
            'flw_ref' => 'FLW-MOCK-902',
            'tx_ref' => 'FLW_VA_TINY',
            'charged_amount' => 20.00,
            'amount' => 20.00,
        ]))->assertOk();

        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $customer->id,
                'note' => 'The customer insists this was theirs, so credit it to them.',
            ])
            ->assertSessionHas('error');

        // An approval names a customer. It cannot manufacture kobo the fee consumed,
        // and the refusal the admin reads names the real reason instead of claiming
        // the money had already been paid out.
        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(0, $this->creditedKobo());
        $this->assertSame('held', $held->fresh()->status);
        $this->assertSame(0, WalletTransaction::query()->where('status', 'success')->count());
    }

    public function test_a_verified_transfer_that_matches_no_customer_at_all_still_reaches_the_board(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);

        // Every lookup comes back empty: not this account number, not a request of
        // ours, not a customer's own one-time account. The money is real and the site
        // knew about it, and until now the only proof was a warning in a log file.
        $this->deliverWebhook($this->charge([
            'id' => 903,
            'flw_ref' => 'FLW-MOCK-903',
            'tx_ref' => 'FLW_VA_ORPHAN',
            'account_number' => self::ORPHAN_ACCOUNT,
        ]))->assertOk();

        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(0, $this->creditedKobo());

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('no_matching_customer', $held->reason);
        $this->assertSame([], $held->holder_user_ids);
        $this->assertSame(self::ORPHAN_ACCOUNT, $held->virtual_account_number);
        $this->assertSame(300_000, (int) $held->amount_kobo);
        $this->assertSame('payer@belovedsubp.com', $held->payload['customer']['email']);

        $page = $this->actingAs($this->admin())
            ->get(route('admin.wallet.deposits.held'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No customer here can be matched to this transfer', $page);
        $this->assertStringContainsString(self::ORPHAN_ACCOUNT, $page);
    }

    public function test_an_admin_can_name_a_customer_for_a_transfer_that_had_no_candidate(): void
    {
        $admin = $this->admin();
        $payer = $this->member(['virtual_account_number' => self::OTHER_ACCOUNT]);

        $this->deliverWebhook($this->charge([
            'id' => 903,
            'flw_ref' => 'FLW-MOCK-903',
            'tx_ref' => 'FLW_VA_ORPHAN',
            'account_number' => self::ORPHAN_ACCOUNT,
        ]))->assertOk();

        $held = FlutterwaveHeldDeposit::query()->sole();

        // The candidate list is empty, which is the one case where the restriction
        // "choose between these customers" cannot be honoured. The board then asks for
        // a user id a human has proved, and the audit record says plainly that nobody
        // was ever a candidate for it.
        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $payer->id,
                'note' => 'Flutterwave support confirmed the sender email belongs to this customer, and the bank transfer matches.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(295_000, $this->balance($payer));
        $this->assertSame('credited', $held->fresh()->status);

        $audit = DB::table('admin_activity_logs')->where('action', 'held_deposit.credit')->sole();
        $changes = json_decode((string) $audit->changes, true);
        $this->assertTrue($changes['named_without_candidates']);
        $this->assertSame('no_matching_customer', $changes['held_because']);
    }

    public function test_a_name_for_a_nonexistent_account_on_a_no_candidate_hold_is_refused(): void
    {
        $admin = $this->admin();

        $this->deliverWebhook($this->charge([
            'id' => 903,
            'flw_ref' => 'FLW-MOCK-903',
            'tx_ref' => 'FLW_VA_ORPHAN',
            'account_number' => self::ORPHAN_ACCOUNT,
        ]))->assertOk();

        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => 999_999,
                'note' => 'Typing an id that is not in this database and hoping it matches.',
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertSame('held', $held->fresh()->status);
        $this->assertSame(0, $this->creditedKobo());
    }

    public function test_a_completed_charge_that_carries_no_reference_of_ours_is_written_down(): void
    {
        $this->deliverWebhook($this->charge([
            'id' => 904,
            'flw_ref' => 'FLW-MOCK-904',
            'tx_ref' => '',
            'payment_type' => 'card',
        ]))->assertOk();

        $held = FlutterwaveHeldDeposit::query()->sole();
        $this->assertSame('no_reference_in_webhook', $held->reason);
        $this->assertSame('flutterwave', $held->channel);
        $this->assertSame([], $held->holder_user_ids);
        $this->assertSame(0, $this->creditedKobo());
    }

    public function test_one_lost_transfer_reported_twice_becomes_one_record_not_a_queue(): void
    {
        $charge = $this->charge([
            'id' => 903,
            'flw_ref' => 'FLW-MOCK-903',
            'tx_ref' => 'FLW_VA_ORPHAN',
            'account_number' => self::ORPHAN_ACCOUNT,
        ]);

        // Flutterwave retries a webhook it is unsure was delivered, and the customer
        // presses "check my deposit" while it does. Support must see one transfer.
        $this->deliverWebhook($charge)->assertOk();
        $this->deliverWebhook($charge)->assertOk();
        app(WalletFundingService::class)->recordUnclaimedCharge(
            $charge,
            'flutterwave_virtual_account',
            'deposit_check'
        );

        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count());
        $this->assertSame(0, $this->creditedKobo());
    }

    public function test_a_full_size_deposit_into_a_number_one_customer_holds_still_credits_with_no_record_on_the_board(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $request = $this->fundingRequest($customer, 'FLW_VA_GOOD');

        $this->deliverWebhook($this->charge([
            'id' => 905,
            'flw_ref' => 'FLW-MOCK-905',
            'tx_ref' => 'FLW_VA_GOOD',
        ]))->assertOk();

        // The whole point of the guard above this line: a deposit that answers its own
        // request at its own amount must pass straight through, or protecting old money
        // would have cost the site its ability to take new money.
        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame('success', $request->fresh()->status);
        $this->assertSame(0, FlutterwaveHeldDeposit::query()->count());
    }

    public function test_a_second_genuine_deposit_into_the_same_account_credits_after_the_first_one_became_a_hold(): void
    {
        $customer = $this->member(['virtual_account_number' => self::SHARED_ACCOUNT]);
        $this->fundingRequest($customer, 'FLW_VA_SHORT', ['amount' => 300_000]);

        // A short arrival is held against the permanent account, which its owner will
        // keep paying into. The hold must attach to that transfer, not to the account.
        $this->deliverWebhook($this->charge([
            'id' => 901,
            'flw_ref' => 'FLW-MOCK-901',
            'tx_ref' => 'FLW_VA_SHORT',
            'charged_amount' => 2500.00,
            'amount' => 2500.00,
        ]))->assertOk();

        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count());

        $this->assertSame(145_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->fresh()->wallet,
            $this->charge(['id' => 906, 'flw_ref' => 'FLW-MOCK-906', 'tx_ref' => 'FLW_VA_LATER', 'charged_amount' => 1500.00, 'amount' => 1500.00]),
            'flutterwave_virtual_account',
            'deposit_check'
        ));

        $this->assertSame(145_000, $this->balance($customer));
        $this->assertSame(1, FlutterwaveHeldDeposit::query()->count(), 'A later transfer into the same account was held behind the shortfall before it.');
    }
}
