<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AdminActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The admin trail is evidence, so these tests treat it the way a court would:
 * nothing may be edited, nothing may be lost, and above all nothing may be
 * written that would let a later reader reconstruct a password, a token or an
 * identity number.
 *
 * The money rule is the one the owner set: a manual balance change without a
 * stated reason is refused before the balance moves, and the record of a change
 * that did happen commits together with the change. A refusal that still wrote a
 * success line, or a credit that rolled back and left its entry behind, would
 * both be worse than no trail at all.
 */
class AdminActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function admin(string $name = 'Queue Admin'): User
    {
        // The profile name is what every admin screen prints, and the factory
        // builds it from first/last - so a test that sets only `name` would be
        // looking for a string the page never shows.
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $admin = User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function member(int $balanceKobo = 0): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => $balanceKobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function recorder(): AdminActivityRecorder
    {
        return app(AdminActivityRecorder::class);
    }

    public function test_a_hand_credit_is_refused_before_the_balance_moves_when_no_reason_is_given(): void
    {
        $admin = $this->admin();
        $member = $this->member(50_000);

        $this->actingAs($admin)
            ->post(route('admin.users.fund-wallet', $member), ['amount' => 2500])
            ->assertSessionHasErrors('note');

        $this->assertSame(50_000, $this->balance($member), 'A credit with no reason still moved money.');
        $this->assertSame(0, WalletTransaction::query()->where('wallet_id', $member->wallet->id)->count());

        // The refusal itself belongs in the trail - an admin who repeatedly submits
        // unexplained credits is a finding - but it must never read as a credit.
        $this->assertSame(0, AdminActivityLog::query()->where('action', 'wallet.fund')->count());
        $this->assertSame(0, AdminActivityLog::query()->where('result', 'success')->count(), 'A refused submit was recorded as a success.');

        $refusal = AdminActivityLog::query()->where('action', 'form.validation_failed')->firstOrFail();
        $this->assertSame('failed', $refusal->result);
        $this->assertSame(['note'], array_values((array) ($refusal->changes['failed_fields'] ?? [])));
    }

    public function test_a_reason_shorter_than_ten_characters_is_refused_too(): void
    {
        $admin = $this->admin();
        $member = $this->member(50_000);

        $this->actingAs($admin)
            ->post(route('admin.users.fund-wallet', $member), ['amount' => 2500, 'note' => 'oops'])
            ->assertSessionHasErrors('note');

        $this->assertSame(50_000, $this->balance($member));
    }

    public function test_a_wallet_reduction_without_a_reason_is_refused_before_the_debit(): void
    {
        $admin = $this->admin();
        $member = $this->member(50_000);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-wallet', $member), [
                'adjustment_type' => 'debit',
                'amount' => 1000,
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(50_000, $this->balance($member), 'An unexplained debit still took money.');
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_a_credited_wallet_stores_the_reason_alongside_the_reference(): void
    {
        $admin = $this->admin();
        $member = $this->member(0);

        $this->actingAs($admin)
            ->post(route('admin.users.fund-wallet', $member), ['amount' => 2500, 'note' => 'Refund for the duplicate data debit'])
            ->assertSessionHas('success');

        $this->assertSame(250_000, $this->balance($member));

        $ledgerRow = WalletTransaction::query()->where('wallet_id', $member->wallet->id)->latest('id')->firstOrFail();
        $trail = AdminActivityLog::query()->where('action', 'wallet.fund')->firstOrFail();

        $this->assertSame('success', $trail->result);
        $this->assertSame('wallet', $trail->category);
        $this->assertSame($admin->id, (int) $trail->admin_user_id);
        $this->assertSame('Refund for the duplicate data debit', $trail->reason);
        $this->assertSame($ledgerRow->reference, $trail->reference, 'The trail must point at the ledger row it describes.');
        $this->assertSame($admin->name, $trail->admin_name);
    }

    public function test_the_trail_entry_and_the_money_move_commit_or_fail_together(): void
    {
        $admin = $this->admin();
        $member = $this->member(0);

        // The cheapest honest way to prove atomicity: make the trail unwritable and
        // show the credit disappears with it. If the entry were written after the
        // transaction, the balance would move anyway and the ledger would be the
        // thing left behind - a change nobody could explain afterwards.
        Schema::drop('admin_activity_logs');

        $this->actingAs($admin)
            ->post(route('admin.users.fund-wallet', $member), ['amount' => 2500, 'note' => 'Refund for the duplicate data debit'])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->balance($member), 'Money moved even though its audit entry could not be written.');
        $this->assertSame(0, WalletTransaction::query()->where('wallet_id', $member->wallet->id)->count());
    }

    public function test_records_cannot_be_edited_or_removed_through_the_model(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->recorder()->success('customer', 'customer.test', 'A recorded action.');
        $log = AdminActivityLog::query()->firstOrFail();

        try {
            $log->forceFill(['description' => 'Rewritten after the fact'])->save();
            $this->fail('An admin activity record was allowed to be modified.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be modified', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('An admin activity record was allowed to be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertSame('A recorded action.', AdminActivityLog::query()->value('description'));
        $this->assertSame(1, AdminActivityLog::query()->count());
    }

    public function test_the_same_event_in_one_request_is_stored_once(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $context = ['target' => ['user', 7], 'reference' => 'REF-7'];
        $this->recorder()->success('customer', 'customer.duplicated', 'Written twice on purpose.', $context);
        $this->recorder()->success('customer', 'customer.duplicated', 'Written twice on purpose.', $context);

        $this->assertSame(1, AdminActivityLog::query()->count());

        // A different ending is a different event, so it is not swallowed.
        $this->recorder()->denied('customer', 'customer.duplicated', 'Refused instead.', $context);
        $this->assertSame(2, AdminActivityLog::query()->count());
    }

    public function test_an_identity_number_in_the_text_is_masked_before_it_is_stored(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $nin = '12345678901';
        $this->recorder()->failed('wallet', 'wallet.mask_check', 'Customer 08012345678 paid with NIN '.$nin.'.', [
            'reason' => 'BVN 22233344455 also on file',
            'changes' => [
                'note' => 'card 5555 5555 5555 4444',
                'password' => 'hunter2',
                'api_key' => 'FLW-live-secret',
                'otp' => '904321',
            ],
        ]);

        $log = AdminActivityLog::query()->firstOrFail();
        $raw = $log->description.' '.$log->reason.' '.json_encode($log->changes);

        foreach ([$nin, '08012345678', '22233344455', '5555555555554444', 'hunter2', 'FLW-live-secret', '904321'] as $secret) {
            $this->assertStringNotContainsString($secret, str_replace(' ', '', $raw), "Stored verbatim: {$secret}");
        }

        $this->assertStringContainsString('****', $raw, 'Nothing was masked, so the digits were kept.');
        $this->assertArrayNotHasKey('password', (array) $log->changes);
        $this->assertArrayNotHasKey('api_key', (array) $log->changes);
        $this->assertArrayNotHasKey('otp', (array) $log->changes);
    }

    public function test_the_audit_screens_are_closed_to_every_admin_until_an_owner_is_named(): void
    {
        $admin = $this->admin();

        foreach (['/admin/administrators', '/admin/activity-log'] as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
        }

        $denied = AdminActivityLog::query()->where('action', 'audit.access_denied')->first();
        $this->assertNotNull($denied, 'A refused attempt at the audit screens must still be recorded.');
        $this->assertSame('denied', $denied->result);
        $this->assertSame($admin->id, (int) $denied->admin_user_id);
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login_page_and_nothing_is_claimed_about_them(): void
    {
        $this->get('/admin/administrators')
            ->assertRedirect(route('login', absolute: false));

        // The auth guard answers before the admin screens are ever reached, so the
        // only honest record of an anonymous probe at a URL is no record at all:
        // there is no account to name and no action to attribute.
        $this->assertSame(0, AdminActivityLog::query()->count());
    }

    public function test_a_signed_in_account_that_is_not_an_admin_is_refused_and_recorded(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/admin/administrators')->assertForbidden();

        $denied = AdminActivityLog::query()->where('action', 'admin.access_denied')->firstOrFail();
        $this->assertSame('denied', $denied->result);
        $this->assertSame($member->id, (int) $denied->admin_user_id);
        $this->assertStringNotContainsString($member->email, (string) $denied->reason);
    }

    public function test_an_action_with_no_signed_in_account_is_recorded_anonymously(): void
    {
        $this->recorder()->denied('auth', 'auth.login_failed', 'A sign-in was refused.', [
            'target' => ['guard', 'web'],
            'reason' => 'Credentials did not match any account.',
        ]);

        $log = AdminActivityLog::query()->firstOrFail();
        $this->assertNull($log->admin_user_id);
        $this->assertNull($log->admin_name, 'A visitor we cannot identify must not be given a label.');
    }

    public function test_a_non_admin_account_never_sees_administrator_data(): void
    {
        $member = $this->member(25_000);

        $this->actingAs($member)->get('/admin/administrators')->assertForbidden();
        $this->actingAs($member)->get('/admin/activity-log')->assertForbidden();
    }

    public function test_the_named_owner_reads_the_administrators_page_with_honest_columns(): void
    {
        $owner = $this->admin('Owner Admin');
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $quiet = User::factory()->create([
            'first_name' => 'Quiet',
            'last_name' => 'Admin',
            'name' => 'Quiet Admin',
            'email_verified_at' => now(),
        ]);
        $quiet->forceFill(['is_admin' => true, 'last_login_at' => null, 'last_login_ip' => null])->save();

        $this->recorderWithActor($owner);

        $html = $this->actingAs($owner)->get('/admin/administrators')->assertOk()->getContent();

        $this->assertStringContainsString('Owner Admin', $html);
        $this->assertStringContainsString('Quiet Admin', $html);
        $this->assertStringContainsString('Audit owner', $html);
        $this->assertStringContainsString('Not recorded', $html, 'An unrecorded login was presented as a time.');
        $this->assertStringNotContainsString('Super Admin', $html, 'The page invented a role the application does not have.');
    }

    public function test_the_owner_reads_the_trail_and_a_single_account_history(): void
    {
        $owner = $this->admin('Owner Admin');
        $other = $this->admin('Second Admin');
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $this->actingAs($owner);
        $this->recorder()->success('wallet', 'wallet.fund', 'Credited 2,500.00 by hand.', ['reference' => 'ADMINFUND-TEST-1']);

        $this->actingAs($other);
        $this->recorder()->denied('platform', 'settings.updated', 'Tried to change a setting.', ['target' => ['setting', 'flutterwave_secret']]);

        $all = $this->actingAs($owner)->get('/admin/activity-log')->assertOk()->getContent();
        $this->assertStringContainsString('wallet.fund', $all);
        $this->assertStringContainsString('ADMINFUND-TEST-1', $all);
        $this->assertStringContainsString('settings.updated', $all);

        $onlyHers = $this->actingAs($owner)->get('/admin/activity-log?admin='.$owner->id)->assertOk()->getContent();
        $this->assertStringContainsString('wallet.fund', $onlyHers);
        $this->assertStringNotContainsString('settings.updated', $onlyHers, 'The per-account filter leaked another admin\'s row.');

        $refusals = $this->actingAs($owner)->get('/admin/activity-log?result=denied')->assertOk()->getContent();
        $this->assertStringContainsString('settings.updated', $refusals);
        $this->assertStringNotContainsString('wallet.fund', $refusals);
    }

    public function test_a_filtered_trail_query_counts_across_pages_not_the_visible_rows(): void
    {
        $owner = $this->admin('Owner Admin');
        config(['audit.owner_user_ids' => [(int) $owner->id]]);
        $this->actingAs($owner);

        for ($i = 1; $i <= 12; $i++) {
            $this->recorder()->success('order', 'order.keys_viewed', 'Opened keys for order '.$i.'.', [
                'target' => ['order', $i],
            ]);
        }

        $html = $this->actingAs($owner)->get('/admin/activity-log?per_page=10')->getContent();

        // 12 recorded, so the Completed figure must read 12 even though 10 rows show.
        $this->assertMatchesRegularExpression('/Completed\s*<[^>]*>\s*<[^>]*>?\s*12/s', $html);
    }

    public function test_reading_the_ledger_records_the_filters_but_not_what_was_typed(): void
    {
        $owner = $this->admin('Owner Admin');
        $member = $this->member(25_000);
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $nin = '87654321098';
        $this->actingAs($owner)->get('/admin/wallet-transactions?search='.$nin.'&status=success')->assertOk();

        $log = AdminActivityLog::query()->where('action', 'wallet.transactions_searched')->firstOrFail();
        $stored = json_encode($log->changes);

        $this->assertStringNotContainsString($nin, $stored, 'A search box can hold an identity number; it must not be copied into the trail.');
        $this->assertStringNotContainsString($nin, (string) $log->description);
        $this->assertSame(['filters', 'results'], array_keys((array) $log->changes));
        $this->assertContains('q', (array) $log->changes['filters'], 'The trail must say which filter was used.');
        $this->assertContains('status', (array) $log->changes['filters']);
    }

    public function test_opening_transaction_screens_moves_no_money(): void
    {
        $owner = $this->admin('Owner Admin');
        $member = $this->member(25_000);
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $before = $this->balance($member);
        $rows = WalletTransaction::query()->count();

        $this->actingAs($owner)->get('/admin/wallet-transactions')->assertOk();
        $this->actingAs($owner)->get(route('admin.users.show', $member))->assertOk();
        $this->actingAs($owner)->get(route('admin.users'))->assertOk();
        $this->actingAs($owner)->get('/admin/wallet-deposits/held')->assertOk();

        $this->assertSame($before, $this->balance($member), 'Viewing a screen changed a balance.');
        $this->assertSame($rows, WalletTransaction::query()->count(), 'Viewing a screen wrote a ledger row.');
    }

    public function test_an_admin_can_see_their_own_history_but_not_edit_it(): void
    {
        $owner = $this->admin('Owner Admin');
        $worker = $this->admin('Desk Admin');
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $this->actingAs($worker);
        $this->recorder()->success('customer', 'customer.discount_changed', 'Changed a discount.', ['target' => ['user', 5]]);

        // The ordinary admin has no screen onto the trail at all...
        $this->actingAs($worker)->get('/admin/activity-log')->assertForbidden();

        // ...and the model refuses a direct rewrite whatever route reaches it.
        $row = AdminActivityLog::query()->where('admin_user_id', $worker->id)->firstOrFail();
        $this->expectException(RuntimeException::class);
        $row->update(['result' => 'success', 'description' => 'Nothing happened.']);
    }

    private function recorderWithActor(User $actor): void
    {
        $this->actingAs($actor);
        $this->recorder()->success('auth', 'auth.login', 'Signed in.', ['actor' => $actor]);
    }
}
