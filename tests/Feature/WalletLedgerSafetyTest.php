<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientWalletBalance;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A wallet is a number that several requests want to change at the same moment: the
 * customer double-tapping Buy, a refund and a purchase, a bank transfer landing while
 * an admin is funding. The old code worked out the new balance in PHP from whatever
 * that request had read, and then wrote the number down - so two requests that each
 * saw "N1,000 available" could each spend it, and the second writer could overwrite
 * money the first one had actually added.
 *
 * These tests hold the line that no movement may be decided from a stale reading: the
 * balance and its ledger row either both happen or neither does, and a refusal says so
 * in the words the purchase screens already answer with.
 */
class WalletLedgerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $refusals = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->repairDatabase();

        parent::tearDown();
    }

    private function ledger(): WalletLedger
    {
        return app(WalletLedger::class);
    }

    private function member(int $kobo): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();

        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id]);
        Wallet::query()->where('id', $wallet->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function held(User $user): int
    {
        return (int) Wallet::query()->where('id', $user->wallet->id)->value('balance');
    }

    private function reference(string $tag): string
    {
        return $tag.'-'.uniqid();
    }

    /** @return \Illuminate\Support\Collection<int, WalletTransaction> */
    private function rows(int $walletId, ?string $type = null)
    {
        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    public function test_two_requests_that_both_saw_the_same_balance_cannot_both_spend_it(): void
    {
        $user = $this->member(100_000);

        // Two requests, one wallet: each loaded the same N1,000.00 before either spent.
        $first = $this->walletOf($user);
        $second = $this->walletOf($user);

        $this->ledger()->debit($first, 80_000, $this->reference('SPEND1'), 'Airtime');

        $this->expectException(InsufficientWalletBalance::class);
        $this->ledger()->debit($second, 80_000, $this->reference('SPEND2'), 'Airtime');
    }

    public function test_a_spend_that_is_refused_leaves_the_balance_and_the_ledger_untouched(): void
    {
        $user = $this->member(100_000);
        $wallet = $this->walletOf($user);

        try {
            $this->ledger()->debit($wallet, 150_000, $this->reference('TOOBIG'), 'Data');
            $this->fail('An unfunded spend was allowed through.');
        } catch (InsufficientWalletBalance $e) {
            // expected
        }

        $this->assertSame(100_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());
    }

    public function test_a_refused_spend_still_says_insufficient_wallet_balance(): void
    {
        $user = $this->member(5_000);
        $wallet = $this->walletOf($user);

        try {
            $this->ledger()->debit($wallet, 50_000, $this->reference('TOOBIG'), 'Data');
            $this->fail('An unfunded spend was allowed through.');
        } catch (InsufficientWalletBalance $e) {
            $this->assertSame('Insufficient wallet balance.', $e->getMessage());
            // The purchase screens answer a RuntimeException with a polite 402.
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function test_a_balance_write_the_database_refuses_leaves_no_ledger_row(): void
    {
        $user = $this->member(100_000);
        $wallet = $this->walletOf($user);

        $this->refuseUpdatesOn('wallets');

        try {
            $this->ledger()->debit($wallet, 20_000, $this->reference('BLOCKED'), 'Cable');
            $this->fail('A refused balance write was passed off as a success.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->repairDatabase();

        $this->assertSame(100_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());
    }

    public function test_a_ledger_row_the_database_refuses_rolls_the_balance_back(): void
    {
        $user = $this->member(100_000);
        $wallet = $this->walletOf($user);

        $this->refuseInsertsOn('wallet_transactions');

        try {
            // The way every purchase flow calls it: inside the request's own transaction.
            DB::transaction(function () use ($wallet): void {
                $this->ledger()->debit($wallet, 20_000, $this->reference('NOROW'), 'Cable');
            });
            $this->fail('Money moved without its ledger row.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->repairDatabase();

        $this->assertSame(100_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());

        // The same money moves normally once the database accepts writes again.
        $this->ledger()->debit($this->walletOf($user), 20_000, $this->reference('RETRY'), 'Cable');
        $this->assertSame(80_000, $this->held($user));
        $this->assertSame(1, $this->rows($wallet->id, 'debit')->count());
    }

    public function test_a_refund_is_recorded_with_the_balance_it_left_behind(): void
    {
        $user = $this->member(70_000);
        $wallet = $this->walletOf($user);

        $this->ledger()->debit($wallet, 70_000, $this->reference('BUY'), 'Electricity');
        $this->ledger()->credit($wallet, 70_000, $this->reference('GIVEBACK'), 'Refund for failed order');

        $rows = $this->rows($wallet->id);
        $this->assertSame(['debit', 'credit'], $rows->pluck('type')->all());
        $this->assertSame(70_000, (int) $rows[0]->meta['balance_before_kobo']);
        $this->assertSame(0, (int) $rows[0]->meta['balance_after_kobo']);
        $this->assertSame(0, (int) $rows[1]->meta['balance_before_kobo']);
        $this->assertSame(70_000, (int) $rows[1]->meta['balance_after_kobo']);
        $this->assertSame(70_000, $this->held($user));
    }

    public function test_two_arrivals_on_one_wallet_both_show_up(): void
    {
        $user = $this->member(0);

        // A webhook and the deposit sweep, each holding the wallet as it was at N0.
        $transfer = $this->walletOf($user);
        $funding = $this->walletOf($user);

        $this->ledger()->credit($transfer, 100_000, $this->reference('TRANSFER'), 'Bank transfer');
        $this->ledger()->credit($funding, 50_000, $this->reference('CHECKOUT'), 'Card checkout');

        $this->assertSame(150_000, $this->held($user));

        $rows = $this->rows($this->walletOf($user)->id, 'credit');
        $this->assertSame(2, $rows->count());
        $this->assertSame(0, (int) $rows[0]->meta['balance_before_kobo']);
        $this->assertSame(100_000, (int) $rows[0]->meta['balance_after_kobo']);
        $this->assertSame(100_000, (int) $rows[1]->meta['balance_before_kobo']);
        $this->assertSame(150_000, (int) $rows[1]->meta['balance_after_kobo']);
    }

    public function test_money_never_moves_backwards_through_a_negative_amount(): void
    {
        $user = $this->member(50_000);
        $wallet = $this->walletOf($user);

        foreach (['debit', 'credit'] as $type) {
            try {
                $this->ledger()->{$type}($wallet, -20_000, $this->reference('SIGN'), 'Signed amount');
                $this->fail("A negative {$type} was accepted.");
            } catch (\InvalidArgumentException $e) {
                // expected
            }
        }

        $this->assertSame(50_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());
    }

    public function test_a_zero_refund_is_still_recorded(): void
    {
        $user = $this->member(50_000);
        $wallet = $this->walletOf($user);

        // MySQL reports "nothing changed" for a zero movement; that must not look like
        // a wallet that cannot be paid into.
        $this->ledger()->credit($wallet, 0, $this->reference('ZEROREFUND'), 'Nothing to refund');

        $this->assertSame(50_000, $this->held($user));
        $this->assertSame(1, $this->rows($wallet->id, 'credit')->count());
    }

    public function test_a_rolled_back_purchase_keeps_neither_money_nor_row(): void
    {
        $user = $this->member(60_000);
        $wallet = $this->walletOf($user);

        try {
            DB::transaction(function () use ($wallet): void {
                $this->ledger()->debit($wallet, 60_000, $this->reference('ORDER'), 'Data');
                $this->assertSame(0, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance'));
                $this->assertSame(1, $this->rows($wallet->id)->count());

                throw new \RuntimeException('The provider never answered.');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('The provider never answered.', $e->getMessage());
        }

        $this->assertSame(60_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());
    }

    public function test_a_debit_leaves_the_wallet_object_holding_what_the_database_holds(): void
    {
        $user = $this->member(30_000);
        $wallet = $this->walletOf($user);

        $this->ledger()->debit($wallet, 10_000, $this->reference('SYNC'), 'Airtime');

        $this->assertSame(20_000, (int) $wallet->balance);
        $this->assertSame(20_000, $this->held($user));
    }

    public function test_the_admin_funding_route_moves_the_balance_and_books_one_row(): void
    {
        $admin = $this->member(0);
        $admin->forceFill(['is_admin' => true])->save();
        $customer = $this->member(12_000);

        $this->actingAs($admin)
            ->post(route('admin.users.fund-wallet', $customer), ['amount' => '1500.00', 'note' => 'Manual bank confirm'])
            ->assertSessionHas('success');

        $wallet = $this->walletOf($customer);
        $this->assertSame(162_000, $this->held($customer));

        $row = $this->rows($wallet->id, 'credit')->first();
        $this->assertNotNull($row);
        $this->assertSame(150_000, (int) $row->amount);
        $this->assertSame('admin_manual_credit', $row->channel);
        $this->assertSame(12_000, (int) $row->meta['balance_before_kobo']);
        $this->assertSame(162_000, (int) $row->meta['balance_after_kobo']);
    }

    public function test_an_admin_setting_a_balance_books_only_the_difference(): void
    {
        $admin = $this->member(0);
        $admin->forceFill(['is_admin' => true])->save();
        $customer = $this->member(70_000);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-wallet', $customer), [
                'adjustment_type' => 'set',
                'amount' => '500.00',
                'note' => 'Corrected after fee review',
            ])
            ->assertSessionHas('success');

        $this->assertSame(50_000, $this->held($customer));

        $rows = $this->rows($this->walletOf($customer)->id);
        $this->assertSame(1, $rows->count());
        $this->assertSame('debit', $rows[0]->type);
        $this->assertSame(20_000, (int) $rows[0]->amount);
        $this->assertSame(70_000, (int) $rows[0]->meta['balance_before_kobo']);
        $this->assertSame(50_000, (int) $rows[0]->meta['balance_after_kobo']);
    }

    public function test_an_admin_cannot_take_a_wallet_below_zero(): void
    {
        $admin = $this->member(0);
        $admin->forceFill(['is_admin' => true])->save();
        $customer = $this->member(30_000);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-wallet', $customer), [
                'adjustment_type' => 'debit',
                'amount' => '500.00',
                'note' => 'Reversal',
            ])
            ->assertSessionHas('error');

        $this->assertSame(30_000, $this->held($customer));
        $this->assertSame(0, $this->rows($this->walletOf($customer)->id)->count());
    }

    public function test_one_withdrawal_pot_cannot_be_paid_out_twice(): void
    {
        $user = $this->member(0);
        $user->forceFill(['referral_earnings_balance' => 50_000])->save();

        $first = $this->actingAs($user)->post(route('referral.withdraw'), ['amount' => '500.00']);
        $first->assertSessionHas('success');

        // A second request for the same pot, as a double tap or a retry would make it.
        $second = $this->actingAs($user)->post(route('referral.withdraw'), ['amount' => '500.00']);
        $second->assertSessionHas('error');

        $this->assertSame(50_000, $this->held($user));
        $this->assertSame(0, (int) DB::table('users')->where('id', $user->id)->value('referral_earnings_balance'));
        $this->assertSame(50_000, (int) DB::table('users')->where('id', $user->id)->value('referral_earnings_withdrawn'));
        $this->assertSame(1, $this->rows($this->walletOf($user)->id, 'credit')->count());
    }

    public function test_shift_moves_the_balance_and_reports_the_pair_it_settled_on(): void
    {
        $user = $this->member(40_000);
        $wallet = $this->walletOf($user);

        // The deposit sweep and admin funding write their own ledger row, so they take
        // the pair from the write itself rather than from a number they read earlier.
        [$before, $after] = $this->ledger()->shift($wallet, 'credit', 10_000);

        $this->assertSame(40_000, $before);
        $this->assertSame(50_000, $after);
        $this->assertSame(50_000, $this->held($user));
        $this->assertSame(0, $this->rows($wallet->id)->count());

        [$before, $after] = $this->ledger()->shift($wallet, 'debit', 5_000);
        $this->assertSame(50_000, $before);
        $this->assertSame(45_000, $after);
    }

    public function test_shift_refuses_a_debit_the_wallet_cannot_cover(): void
    {
        $user = $this->member(4_000);
        $wallet = $this->walletOf($user);

        $this->expectException(InsufficientWalletBalance::class);
        $this->ledger()->shift($wallet, 'debit', 5_000);
    }

    private function refuseUpdatesOn(string $table): void
    {
        $this->breakSql('refuse_'.$table.'_update', 'BEFORE UPDATE ON '.$table);
    }

    private function refuseInsertsOn(string $table): void
    {
        $this->breakSql('refuse_'.$table.'_insert', 'BEFORE INSERT ON '.$table);
    }

    private function breakSql(string $name, string $when): void
    {
        $this->refusals[] = $name;
        DB::unprepared('CREATE TRIGGER '.$name.' '.$when." BEGIN SELECT RAISE(ABORT, 'the database refused the write'); END");
    }

    private function repairDatabase(): void
    {
        foreach ($this->refusals as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }

        $this->refusals = [];
    }
}
