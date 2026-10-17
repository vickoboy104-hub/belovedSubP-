<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A charge this site has already dealt with must never pay a customer a second time
 * by itself.
 *
 * The duplicate guard in WalletFundingService only recognises money it has already
 * BANKED. It has nothing to compare against when an admin settled the customer by
 * hand somewhere else, and a manual credit carries no charge identity at all, so a
 * later report of the same transfer sails through every check and credits again.
 * These tests hold such an arrival for a human instead, and keep proving the two
 * things that must not break: a genuinely new charge still funds normally, and a
 * second real transfer into a permanent account is not mistaken for the first.
 */
class FlutterwavePriorSettlementHoldTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '07777777777';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'test-secret-key',
            'services.flutterwave.secret_hash' => 'test-verif-hash',
        ]);
    }

    private function member(array $attributes = []): User
    {
        $isAdmin = (bool) ($attributes['is_admin'] ?? false);
        unset($attributes['is_admin']);

        $user = User::factory()->create($attributes);
        $user->forceFill([
            'email_verified_at' => now(),
            'is_admin' => $isAdmin,
            'virtual_account_number' => array_key_exists('virtual_account_number', $attributes)
                ? $attributes['virtual_account_number']
                : self::ACCOUNT,
        ])->save();

        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function charge(array $overrides = []): array
    {
        return array_merge([
            'id' => 9001,
            'tx_ref' => 'FLW_VA_REQ_ONE',
            'flw_ref' => 'FLW-MOCK-9001',
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => 3000.00,
            'amount' => 3000.00,
            'payment_type' => 'bank_transfer',
            'account_number' => self::ACCOUNT,
            'created_at' => now()->toIso8601String(),
            'customer' => ['email' => 'payer@example.test'],
        ], $overrides);
    }

    private function ledgerRow(User $user, array $attributes): WalletTransaction
    {
        return WalletTransaction::query()->create(array_merge([
            'wallet_id' => $user->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'status' => 'pending',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Wallet funding requested',
            'meta' => [],
        ], $attributes));
    }

    private function balance(User $user): int
    {
        return (int) $user->fresh()->wallet->balance;
    }

    public function test_a_charge_already_closed_as_failed_on_this_wallet_is_held_not_credited(): void
    {
        $customer = $this->member();
        $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'status' => 'failed',
            'meta' => [
                'tx_ref' => 'FLW_VA_REQ_ONE',
                'flutterwave_charge_id' => '9001',
                'flw_ref' => 'FLW-MOCK-9001',
                'flutterwave_payload' => ['id' => 9001, 'flw_ref' => 'FLW-MOCK-9001'],
                'gateway_response' => 'funded by a transfer that never landed',
            ],
        ]);

        $credited = app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'deposit_check'
        );

        $this->assertSame(0, $credited, 'A charge the ledger already closed as failed was paid out again.');
        $this->assertSame(0, $this->balance($customer));
        $this->assertSame(0, WalletTransaction::query()->where('status', 'success')->count());

        $held = DB::table('flutterwave_held_deposits')->get();
        $this->assertCount(1, $held);
        $this->assertSame('charge_previously_reported_failed', $held[0]->reason);
        $this->assertSame('held', $held[0]->status);
    }

    public function test_the_hold_leaves_the_global_claim_free_so_an_admin_can_still_pay_it(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true]);

        $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'status' => 'failed',
            'meta' => ['flutterwave_charge_id' => '9001', 'flw_ref' => 'FLW-MOCK-9001'],
        ]);

        $funding = app(WalletFundingService::class);
        $funding->creditFlutterwaveCharge($customer->wallet, $this->charge(), 'flutterwave_virtual_account', 'webhook');

        $this->assertSame(
            0,
            DB::table('flutterwave_charge_claims')->count(),
            'A deposit that paid nobody consumed its global claim, locking the money out of the wallet that owns it.'
        );
        $this->assertSame(
            0,
            (int) DB::table('flutterwave_charge_claims')->where('charge_token', '9001')->count()
        );

        $heldId = DB::table('flutterwave_held_deposits')->value('id');
        $this->assertNotNull($heldId);

        $credited = $funding->creditHeldDepositTo(
            \App\Models\FlutterwaveHeldDeposit::query()->findOrFail($heldId),
            $customer,
            $admin,
            'Owner read the transfer and confirmed it is this customer.'
        );

        $this->assertSame(295_000, $credited, 'A held deposit that an admin released could not be paid.');
        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(1, WalletTransaction::query()->where('status', 'success')->count());
    }

    public function test_one_held_arrival_is_recorded_once_however_many_paths_report_it(): void
    {
        $customer = $this->member();
        $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'status' => 'failed',
            'meta' => ['flutterwave_charge_id' => '9001', 'flw_ref' => 'FLW-MOCK-9001'],
        ]);

        $funding = app(WalletFundingService::class);

        foreach (['webhook', 'deposit_check', 'callback'] as $via) {
            $this->assertSame(0, $funding->creditFlutterwaveCharge(
                $customer->wallet,
                $this->charge(),
                'flutterwave_virtual_account',
                $via
            ));
        }

        $this->assertSame(0, $this->balance($customer));
        $this->assertCount(1, DB::table('flutterwave_held_deposits')->get());
    }

    public function test_a_funding_request_marked_settled_by_hand_holds_its_later_arrival(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true]);

        $request = $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'status' => 'pending',
            'meta' => ['tx_ref' => 'FLW_VA_REQ_ONE', 'virtual_account_number' => self::ACCOUNT],
        ]);

        $this->actingAs($admin)->post('/admin/wallet-requests/'.$request->id.'/mark-settled', [
            'note' => 'Credited from the support desk on the phone call, same money.',
        ])->assertRedirect();

        $this->assertSame(0, $this->balance($customer), 'Marking a request settled moved money.');

        $request->refresh();
        $this->assertTrue($request->meta['manually_settled'] ?? null);

        $credited = app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'deposit_check'
        );

        $this->assertSame(0, $credited, 'The customer was paid twice for one transfer: manual help, then the arrival.');
        $this->assertSame(0, $this->balance($customer));

        $held = DB::table('flutterwave_held_deposits')->first();
        $this->assertNotNull($held);
        $this->assertSame('already_settled_manually', $held->reason);
    }

    public function test_marking_a_request_settled_refuses_an_empty_note(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true]);
        $request = $this->ledgerRow($customer, ['reference' => 'FLW_VA_REQ_ONE']);

        foreach (['', '   ', 'short'] as $note) {
            $this->actingAs($admin)
                ->post('/admin/wallet-requests/'.$request->id.'/mark-settled', ['note' => $note])
                ->assertSessionHasErrors('note');
        }

        $this->assertNull($request->refresh()->meta['manually_settled'] ?? null);
        $this->assertSame(0, $this->balance($customer));
    }

    public function test_a_row_that_is_not_an_open_funding_request_cannot_be_marked(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true]);

        $success = $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_DONE',
            'status' => 'success',
            'meta' => ['flutterwave_charge_id' => '9001'],
        ]);
        $debit = $this->ledgerRow($customer, [
            'reference' => 'SOME_DEBIT',
            'type' => 'debit',
            'status' => 'success',
            'channel' => 'wallet',
        ]);

        $this->actingAs($admin)
            ->post('/admin/wallet-requests/'.$success->id.'/mark-settled', ['note' => 'a long enough reason'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->post('/admin/wallet-requests/'.$debit->id.'/mark-settled', ['note' => 'a long enough reason'])
            ->assertStatus(422);

        $this->assertNull($success->refresh()->meta['manually_settled'] ?? null);
        $this->assertSame(0, $this->balance($customer));
    }

    public function test_reopening_a_request_returns_it_to_the_normal_funding_path(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true]);

        $request = $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'meta' => ['manually_settled' => true, 'manually_settled_note' => 'marked in error'],
        ]);

        $this->actingAs($admin)
            ->post('/admin/wallet-requests/'.$request->id.'/unmark-settled', [
                'note' => 'The support credit never went through; this money is still owed.',
            ])
            ->assertRedirect();

        $this->assertFalse($request->refresh()->meta['manually_settled'] ?? null);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'deposit_check'
        ));
        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(0, DB::table('flutterwave_held_deposits')->count());
    }

    public function test_a_brand_new_charge_still_funds_the_wallet_without_asking_anyone(): void
    {
        $customer = $this->member();

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        ));

        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(0, DB::table('flutterwave_held_deposits')->count());
    }

    public function test_a_second_transfer_into_the_same_account_is_credited_even_when_the_first_request_failed(): void
    {
        $customer = $this->member();

        // The first funding request was closed out without any money arriving. A
        // later transfer is a different charge and real money, so the hold must not
        // reach back to the failed row through the shared account or reference.
        $this->ledgerRow($customer, [
            'reference' => 'FLW_VA_REQ_ONE',
            'status' => 'failed',
            'description' => 'No transfer arrived for this funding request. Generate a new account number to try again.',
            'meta' => ['tx_ref' => 'FLW_VA_REQ_ONE', 'virtual_account_number' => self::ACCOUNT],
        ]);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(['id' => 9002, 'flw_ref' => 'FLW-MOCK-9002']),
            'flutterwave_virtual_account',
            'deposit_check'
        ));

        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(0, DB::table('flutterwave_held_deposits')->count(), 'A genuine new deposit was held behind an old failure.');
    }

    public function test_the_hold_only_ever_looks_at_this_wallets_own_history(): void
    {
        $stranger = $this->member(['virtual_account_number' => null]);
        $customer = $this->member();

        $this->ledgerRow($stranger, [
            'reference' => 'SOMEONE_ELSES_FAILED_ROW',
            'status' => 'failed',
            'meta' => ['flutterwave_charge_id' => '9001', 'flw_ref' => 'FLW-MOCK-9001'],
        ]);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        ));

        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(0, $this->balance($stranger));
    }
}
