<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletSettlementLink;
use App\Models\WalletTransaction;
use App\Services\WalletFundingService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A manual settlement is a statement an admin signs about one specific charge, never
 * a relationship the site infers from matching amounts, accounts or customers.
 *
 * These tests hold both halves of that rule: a declaration must hold the exact charge
 * it names and nothing else, and the ordinary funding of a genuinely new payment must
 * keep working untouched. They also pin the two things a declaration must never do -
 * move money by itself, and survive as an unrecorded decision.
 */
class ManualSettlementDeclarationTest extends TestCase
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
        $user = User::factory()->create(array_merge(
            $attributes,
            ['is_admin' => (bool) ($attributes['is_admin'] ?? false)]
        ));

        // An account number names one customer. Staff accounts that never touched
        // a funding request must not share one, or the ownership check would hold
        // arrivals for a reason this test is not about.
        $user->forceFill([
            'email_verified_at' => now(),
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

    /**
     * A row of the kind the desk tool leaves behind. Only the ledger row is written
     * here, never the balance, so every "no money moved" assertion below is about
     * the charge being tested and cannot be polluted by this one.
     */
    private function manualCredit(User $customer, string $channel, int $kobo = 300_000): WalletTransaction
    {
        return WalletTransaction::query()->create([
            'wallet_id' => $customer->wallet->id,
            'type' => 'credit',
            'amount' => $kobo,
            'reference' => 'ADJ'.uniqid(),
            'status' => 'success',
            'channel' => $channel,
            'description' => 'Admin wallet funding',
            'meta' => ['note' => 'Support desk credit'],
        ]);
    }

    private function balance(User $user): int
    {
        return (int) $user->fresh()->wallet->balance;
    }

    public function test_a_declared_charge_is_held_instead_of_credited_when_it_is_reported_again(): void
    {
        $customer = $this->member();
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $link = WalletSettlementLink::query()->create([
            'charge_token' => '9001',
            'charge_token_kind' => 'charge_id',
            'wallet_id' => $customer->wallet->id,
            'manual_transaction_id' => $credit->id,
            'amount_kobo' => $credit->amount,
            'status' => 'active',
            'reason' => 'The desk credited this same transfer by hand on the phone call.',
            'declared_by_user_id' => $this->member(['is_admin' => true, 'virtual_account_number' => null])->id,
            'declared_at' => now(),
        ]);

        $this->assertSame(0, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        ));

        $this->assertSame(0, $this->balance($customer), 'A declared charge paid the customer a second time.');
        $this->assertSame(0, WalletTransaction::query()->where('status', 'success')->where('channel', 'flutterwave_virtual_account')->count());

        $held = DB::table('flutterwave_held_deposits')->first();
        $this->assertNotNull($held, 'The re-reported charge was neither credited nor written down.');
        $this->assertSame('manual_settlement_declared', $held->reason);
        $this->assertSame('9001', $held->flutterwave_charge_id);
        $this->assertNotSame($link->id, 0);
    }

    public function test_a_declaration_only_ever_holds_the_charge_it_names(): void
    {
        $customer = $this->member();
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        WalletSettlementLink::query()->create([
            'charge_token' => '9001',
            'charge_token_kind' => 'charge_id',
            'wallet_id' => $customer->wallet->id,
            'manual_transaction_id' => $credit->id,
            'amount_kobo' => $credit->amount,
            'status' => 'active',
            'reason' => 'Declared against one transfer only.',
            'declared_by_user_id' => $customer->id,
            'declared_at' => now(),
        ]);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(['id' => 9002, 'flw_ref' => 'FLW-MOCK-9002', 'tx_ref' => 'FLW_VA_REQ_TWO']),
            'flutterwave_virtual_account',
            'deposit_check'
        ));

        $this->assertSame(295_000, $this->balance($customer), 'A declaration held a different customer payment hostage.');
        $this->assertSame(0, DB::table('flutterwave_held_deposits')->count());
    }

    public function test_an_unrelated_hand_credit_never_holds_a_new_charge_on_its_own(): void
    {
        $customer = $this->member();

        // Money the desk gave, naming no charge. Without a declaration there is no
        // relationship, and inferring one from the same wallet is exactly the guess
        // the owner forbade.
        $this->manualCredit($customer, 'admin_wallet_adjustment', 30_000);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        ));

        $this->assertSame(295_000, $this->balance($customer));
        $this->assertSame(0, DB::table('flutterwave_held_deposits')->count());
    }

    public function test_declaring_from_the_admin_screen_writes_the_statement_and_its_history_without_paying_anyone(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $credit->id,
            'reason' => 'Read both records: same amount, same day, the customer confirmed the desk credit.',
        ])->assertRedirect()->assertSessionHas('success');

        $link = WalletSettlementLink::query()->where('charge_token', '9001')->sole();
        $this->assertSame('charge_id', $link->charge_token_kind);
        $this->assertSame($credit->id, (int) $link->manual_transaction_id);
        $this->assertSame($admin->id, (int) $link->declared_by_user_id);
        $this->assertSame(0, $this->balance($customer), 'Declaring a settlement moved money.');

        $entry = DB::table('admin_activity_logs')
            ->where('action', 'settlement_link.declare')
            ->where('result', 'success')
            ->sole();
        $this->assertSame($admin->id, (int) $entry->admin_user_id);
        $this->assertSame('9001', $entry->reference);
        $this->assertSame('settlement_link', $entry->target_type);
        $this->assertStringContainsString('same amount', (string) $entry->reason);
        $changes = json_decode((string) $entry->changes, true);
        $this->assertSame('admin_manual_credit', $changes['manual_channel'] ?? null);
        $this->assertSame((int) $credit->id, (int) ($changes['manual_transaction_id'] ?? 0));
        $this->assertSame(300_000, (int) ($changes['amount_kobo'] ?? 0));
    }

    public function test_the_declaration_screen_accepts_both_manual_credit_channels(): void
    {
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);

        foreach (['admin_manual_credit' => '8001', 'admin_wallet_adjustment' => '8002'] as $channel => $token) {
            $customer = $this->member();
            $credit = $this->manualCredit($customer, $channel);

            $this->actingAs($admin)->post('/admin/settlement-links', [
                'charge_id' => $token,
                'manual_transaction_id' => $credit->id,
                'reason' => 'The desk settled this transfer by hand through the '.$channel.' route.',
            ])->assertSessionHas('success');

            $this->assertDatabaseHas('wallet_settlement_links', [
                'charge_token' => $token,
                'manual_transaction_id' => $credit->id,
                'status' => 'active',
            ]);
        }
    }

    public function test_a_declaration_must_name_money_the_desk_actually_paid_by_hand(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);

        $gatewayCredit = $this->manualCredit($customer, 'flutterwave_virtual_account');
        $debit = WalletTransaction::query()->create([
            'wallet_id' => $customer->wallet->id,
            'type' => 'debit',
            'amount' => 5_000,
            'reference' => 'AIRTIME'.uniqid(),
            'status' => 'success',
            'channel' => 'wallet',
            'description' => 'Airtime purchase',
            'meta' => [],
        ]);

        foreach ([$gatewayCredit->id, $debit->id, 999999] as $id) {
            $this->actingAs($admin)->post('/admin/settlement-links', [
                'charge_id' => '9100',
                'manual_transaction_id' => $id,
                'reason' => 'Trying to declare against a row that was never hand-credited.',
            ])->assertSessionHasErrors('manual_transaction_id');
        }

        $this->assertSame(0, WalletSettlementLink::query()->count());
    }

    public function test_a_declaration_cannot_be_made_against_this_sites_own_reference(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        // FLW_... is the reference this site issues for a funding request. A whole
        // permanent account keeps using one such reference, so declaring against it
        // would hold every future transfer into that account.
        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => 'FLW_VA_REQ_ONE',
            'manual_transaction_id' => $credit->id,
            'reason' => 'Naming the site reference instead of the gateway charge id.',
        ])->assertSessionHasErrors('charge_id');

        $this->assertSame(0, WalletSettlementLink::query()->count());
    }

    public function test_one_charge_can_only_ever_carry_one_declaration(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $first = $this->manualCredit($customer, 'admin_manual_credit');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $first->id,
            'reason' => 'The desk settled this transfer by hand.',
        ])->assertSessionHas('success');

        $second = $this->manualCredit($customer, 'admin_wallet_adjustment');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $second->id,
            'reason' => 'A second statement about the same charge.',
        ])->assertSessionHasErrors('charge_id');

        $this->assertSame(1, WalletSettlementLink::query()->count());
        $this->assertSame($first->id, (int) WalletSettlementLink::query()->value('manual_transaction_id'));
    }

    public function test_a_held_charge_cannot_be_released_while_its_declaration_stands(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $credit->id,
            'reason' => 'Already paid by hand; the arrival must not pay it again.',
        ])->assertSessionHas('success');

        app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        );

        $held = DB::table('flutterwave_held_deposits')->sole();
        $heldId = (int) $held->id;
        $this->assertSame('manual_settlement_declared', $held->reason);

        $this->actingAs($admin)->post('/admin/wallet-deposits/held/'.$heldId.'/credit', [
            'user_id' => $customer->id,
            'note' => 'Releasing it because the customer asked for the balance.',
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->balance($customer), 'A declared charge was paid out from the held board.');
        $this->assertSame('held', DB::table('flutterwave_held_deposits')->where('id', $heldId)->value('status'));

        $refusal = DB::table('admin_activity_logs')
            ->where('action', 'held_deposit.credit')
            ->where('result', 'failed')
            ->sole();
        $this->assertStringContainsString('declared already settled by hand', (string) $refusal->description);
    }

    public function test_retracting_a_declaration_returns_the_charge_to_the_normal_funding_path(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $credit->id,
            'reason' => 'Thought this was the desk credit; the customer has now proved otherwise.',
        ])->assertSessionHas('success');

        $link = WalletSettlementLink::query()->sole();

        $this->actingAs($admin)->post('/admin/settlement-links/'.$link->id.'/retract', [
            'note' => 'Short',
        ])->assertSessionHasErrors('note');

        $this->assertSame('active', $link->refresh()->status);

        $this->actingAs($admin)->post('/admin/settlement-links/'.$link->id.'/retract', [
            'note' => 'The desk credit was for a different transfer; this money is genuinely owed.',
        ])->assertSessionHas('success');

        $link->refresh();
        $this->assertSame('retracted', $link->status);
        $this->assertSame($admin->id, (int) $link->retracted_by_user_id);

        $this->assertSame(295_000, app(WalletFundingService::class)->creditFlutterwaveCharge(
            $customer->wallet,
            $this->charge(),
            'flutterwave_virtual_account',
            'webhook'
        ));
        $this->assertSame(295_000, $this->balance($customer));

        $entry = DB::table('admin_activity_logs')->where('action', 'settlement_link.retract')->sole();
        $this->assertSame('9001', $entry->reference);
        $this->assertStringContainsString('different transfer', (string) $entry->reason);
    }

    public function test_a_second_retraction_of_the_same_declaration_changes_nothing(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $link = WalletSettlementLink::query()->create([
            'charge_token' => '9001',
            'charge_token_kind' => 'charge_id',
            'wallet_id' => $customer->wallet->id,
            'manual_transaction_id' => $credit->id,
            'amount_kobo' => $credit->amount,
            'status' => 'retracted',
            'reason' => 'Already retracted before this request.',
            'declared_by_user_id' => $admin->id,
            'declared_at' => now(),
            'retracted_by_user_id' => $admin->id,
            'retracted_at' => now(),
            'retraction_note' => 'The desk credit was for another transfer.',
        ]);

        $this->actingAs($admin)->post('/admin/settlement-links/'.$link->id.'/retract', [
            'note' => 'Trying to retract a statement that is already withdrawn.',
        ])->assertSessionHas('error');

        $this->assertSame(1, WalletSettlementLink::query()->count());
        $this->assertSame(0, DB::table('admin_activity_logs')
            ->where('action', 'settlement_link.retract')
            ->where('result', 'success')
            ->count());
    }

    public function test_the_declaration_board_and_its_post_are_refused_without_the_permission(): void
    {
        $customer = $this->member();
        $declarter = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        // An account that can run the desk but was never given this one power.
        $declarter->permissionGrants()
            ->where('permission', 'wallet.settlement.declare')
            ->get()
            ->each(fn ($grant) => $grant->delete());

        // A declaration that already stands, so the retract attempt has a real row to
        // point at - a missing id would be answered with 404 before the permission
        // check, and would prove nothing about the permission.
        $existing = WalletSettlementLink::query()->create([
            'charge_token' => '9500',
            'charge_token_kind' => 'charge_id',
            'wallet_id' => $customer->wallet->id,
            'manual_transaction_id' => $credit->id,
            'amount_kobo' => $credit->amount,
            'status' => 'active',
            'reason' => 'Declared earlier by somebody who holds the permission.',
            'declared_by_user_id' => $declarter->id,
            'declared_at' => now(),
        ]);

        $this->actingAs($declarter)->get('/admin/settlement-links')->assertForbidden();

        $this->actingAs($declarter)->post('/admin/settlement-links', [
            'charge_id' => '9001',
            'manual_transaction_id' => $credit->id,
            'reason' => 'A form replay from an account that was never given this power.',
        ])->assertForbidden();

        $this->actingAs($declarter)->post('/admin/settlement-links/'.$existing->id.'/retract', [
            'note' => 'A replayed retraction from the same account.',
        ])->assertForbidden();

        $this->assertSame(1, WalletSettlementLink::query()->count(), 'A refused declaration was written anyway.');
        $this->assertSame('9500', (string) WalletSettlementLink::query()->value('charge_token'));
        $this->assertSame('active', $existing->refresh()->status, 'A refused retraction changed a declaration anyway.');
        $this->assertSame(0, $this->balance($customer));

        // The owner asked to see reach-for attempts, not just the ones that worked.
        // Repeated refusals of the same kind from one address are recorded once per
        // window on purpose, so this asserts the record exists rather than its count.
        $denied = DB::table('admin_activity_logs')
            ->where('action', 'admin.permission_denied')
            ->where('result', 'denied')
            ->get();
        $this->assertNotEmpty($denied);
        $this->assertStringContainsString('wallet.settlement.declare', (string) $denied[0]->reason);
    }

    public function test_the_board_lists_what_is_declared_and_who_signed_it(): void
    {
        $customer = $this->member();
        $admin = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        $credit = $this->manualCredit($customer, 'admin_manual_credit');

        $this->actingAs($admin)->post('/admin/settlement-links', [
            'flw_ref' => 'FLW-MOCK-9001',
            'manual_transaction_id' => $credit->id,
            'reason' => 'No charge id on this record, so the statement names its flw_ref.',
        ])->assertSessionHas('success');

        $html = $this->actingAs($admin)->get('/admin/settlement-links')->assertOk()->getContent();

        $this->assertStringContainsString('FLW-MOCK-9001', $html);
        $this->assertStringContainsString(e($admin->name), $html);
        $this->assertStringContainsString('flw_ref', $html);
        $this->assertStringContainsString('Copy', $html, 'A reference nobody can copy is a reference somebody re-types by hand.');
    }

    public function test_a_configured_master_admin_holds_every_permission_from_configuration_alone(): void
    {
        $master = $this->member(['is_admin' => true, 'virtual_account_number' => null]);
        config(['admin.master_user_ids' => [(int) $master->id]]);

        // Whatever the factory handed out is irrelevant to a Master: its power comes
        // from configuration, so revoking every row cannot lock the owner out.
        $this->assertGreaterThan(0, $master->permissionGrants()->count());
        $master->permissionGrants()->delete();

        $this->assertSame(
            AdminPermissions::slugs(),
            $master->fresh()->adminPermissionSlugs(),
            'A Master Admin must hold the whole set from configuration alone.'
        );
        $this->assertSame(
            0,
            $master->permissionGrants()->count(),
            'A Master Admin should not need grant rows at all.'
        );
        $this->assertTrue($master->fresh()->hasAdminPermission('wallet.settlement.declare'));
    }
}
