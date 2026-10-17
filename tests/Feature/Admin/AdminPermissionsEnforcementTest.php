<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What an administrator may do is decided on the server, before a controller runs.
 *
 * Every refusal in this file is a request to a real URL with a real form body,
 * because hiding a link in a menu stops nobody who types the address, follows a link
 * somebody sent them, or replays a request they captured yesterday. The other half of
 * the rule is that a permission someone holds must actually work, and that no session
 * can widen its own reach - neither by editing its own row, nor by naming itself a
 * Master, nor by posting a permission the system never put on its list.
 */
class AdminPermissionsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.owner_user_ids' => []]);
    }

    private function master(): User
    {
        $master = User::factory()->create(['is_admin' => true]);
        config(['admin.master_user_ids' => [(int) $master->id]]);
        $master->permissionGrants()->delete();

        return $master->fresh();
    }

    private function administrator(?array $permissions): User
    {
        return $this->promoteAsAdministrator(
            User::factory()->create(),
            $permissions === null ? null : $permissions
        );
    }

    private function customer(): User
    {
        $customer = User::factory()->create();
        $customer->forceFill(['email_verified_at' => now()])->save();
        \App\Models\Wallet::query()->firstOrCreate(['user_id' => $customer->id], ['balance' => 0]);

        return $customer;
    }

    private function balance(User $user): int
    {
        return (int) $user->fresh()->wallet->balance;
    }

    public function test_a_granted_screen_opens_and_an_unganted_one_is_refused_at_the_url(): void
    {
        $reader = $this->administrator(['customers.view']);

        $this->actingAs($reader)->get('/admin/users')->assertOk();
        $this->actingAs($reader)->get('/admin')->assertOk();

        foreach (['/admin/wallet-transactions', '/admin/wallet-stats', '/admin/settings', '/admin/broadcast'] as $path) {
            $this->actingAs($reader)->get($path)->assertForbidden();
        }
    }

    public function test_a_money_form_on_somebody_else_s_screen_is_refused_when_they_post_it(): void
    {
        $customer = $this->customer();
        $deskOnly = $this->administrator(['customers.view', 'wallet.transactions.view']);

        // The page itself is read-only work, so it renders; the act is what needs the
        // grant. This is the case the owner named: a hidden button stops nobody, and
        // a visible one decides nothing.
        $this->actingAs($deskOnly)->get('/admin/users/'.$customer->id)->assertOk();

        $this->actingAs($deskOnly)->post('/admin/users/'.$customer->id.'/fund-wallet', [
            'amount' => 5000,
            'note' => 'A replayed form post from an account that was never given wallet.credit.',
        ])->assertForbidden();

        $this->assertSame(0, $this->balance($customer), 'A refused funding request still moved money.');
        $this->assertSame(0, DB::table('wallet_transactions')->where('wallet_id', $customer->wallet->id)->count());

        $this->assertNotEmpty(
            DB::table('admin_activity_logs')
                ->where('action', 'admin.permission_denied')
                ->where('result', 'denied')
                ->where('admin_user_id', $deskOnly->id)
                ->get(),
            'A reach for a permission the account does not hold must be visible to the owner.'
        );
    }

    public function test_one_of_the_permissions_a_screen_needs_is_enough_to_open_it(): void
    {
        $pricesOnly = $this->administrator(['prices.edit']);

        // The settings page is one page but three kinds of work: prices, provider
        // routing and site behaviour. Holding any one opens it.
        $this->actingAs($pricesOnly)->get('/admin/settings')->assertOk();

        $nothing = $this->administrator(['customers.view']);
        $this->actingAs($nothing)->get('/admin/settings')->assertForbidden();
    }

    public function test_the_permissions_screen_is_a_master_admin_door_and_nobody_else(): void
    {
        $fullyGranted = $this->administrator(AdminPermissions::slugs());

        $this->assertFalse($fullyGranted->hasAdminPermission(AdminPermissions::MASTER_PERMISSION));

        $this->actingAs($fullyGranted)->get('/admin/permissions')->assertForbidden();
        $this->actingAs($fullyGranted)
            ->post('/admin/permissions/'.$fullyGranted->id, [
                'permissions' => AdminPermissions::slugs(),
                'reason' => 'An account trying to write its own permission set.',
            ])->assertForbidden();

        $this->actingAs($this->master())->get('/admin/permissions')->assertOk();
    }

    public function test_with_no_master_named_in_configuration_the_permission_screens_are_shut(): void
    {
        config(['admin.master_user_ids' => []]);
        $fullyGranted = $this->administrator(AdminPermissions::slugs());

        // Fail closed: the site does not guess which account is the owner's.
        $this->actingAs($fullyGranted)->get('/admin/permissions')->assertForbidden();
    }

    public function test_the_master_permission_itself_can_never_be_granted(): void
    {
        $master = $this->master();
        $target = $this->administrator(['customers.view']);

        $this->actingAs($master)->post('/admin/permissions/'.$target->id, [
            'permissions' => ['customers.view', AdminPermissions::MASTER_PERMISSION],
            'reason' => 'Trying to make an ordinary administrator able to hand out permissions.',
        ])->assertSessionHasErrors('permissions.1');

        $this->assertSame(
            0,
            DB::table('admin_permission_grants')->where('permission', AdminPermissions::MASTER_PERMISSION)->count()
        );
    }

    public function test_nobody_edits_the_permission_set_of_the_session_they_are_using(): void
    {
        // A Master Admin is the only account that can reach this screen, so the guard
        // that matters here is the one that stops that account from writing its own
        // access - the accident and the attack are the same keystroke.
        $master = $this->master();

        $this->actingAs($master)->post('/admin/permissions/'.$master->id, [
            'permissions' => ['customers.view'],
            'reason' => 'Narrowing my own access from the session that holds all of it.',
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'permissions.self_edit',
            'result' => 'failed',
            'admin_user_id' => $master->id,
        ]);
        $this->assertSame(AdminPermissions::slugs(), $master->fresh()->adminPermissionSlugs());
    }

    public function test_a_master_admins_permission_set_cannot_be_edited_by_another_master(): void
    {
        $first = $this->master();
        $second = User::factory()->create(['is_admin' => true]);
        config(['admin.master_user_ids' => [(int) $first->id, (int) $second->id]]);

        $this->actingAs($first)->post('/admin/permissions/'.$second->id, [
            'permissions' => ['customers.view'],
            'reason' => 'One Master trying to narrow another Master down to a single permission.',
        ])->assertSessionHas('error');

        $this->assertTrue($second->fresh()->hasAdminPermission('wallet.credit'));

        $html = $this->actingAs($first)->get('/admin/permissions')->assertOk()->getContent();
        $this->assertStringNotContainsString(
            'action="'.route('admin.permissions.update', $second).'"',
            $html,
            'A Master Admin must not be offered as somebody to grant permissions to.'
        );
        $this->assertStringContainsString('cannot be edited here', $html);
    }

    public function test_every_permission_change_is_recorded_with_its_who_and_why(): void
    {
        $master = $this->master();
        $staff = $this->administrator(['customers.view', 'wallet.transactions.view']);

        $this->actingAs($master)->post('/admin/permissions/'.$staff->id, [
            'permissions' => ['customers.view', 'wallet.credit'],
            'reason' => 'The desk only needs to fund wallets now; the reading screen is theirs already.',
        ])->assertSessionHas('success');

        $entry = DB::table('admin_activity_logs')->where('action', 'permissions.updated')->sole();
        $this->assertSame($master->id, (int) $entry->admin_user_id);
        $this->assertSame('user', $entry->target_type);
        $this->assertSame((string) $staff->id, (string) $entry->target_id);
        $this->assertStringContainsString('The desk only needs to fund wallets', (string) $entry->reason);

        $changes = json_decode((string) $entry->changes, true);
        $this->assertSame(['wallet.transactions.view'], $changes['removed']);
        $this->assertSame(['wallet.credit'], $changes['added']);
        $this->assertEqualsCanonicalizing(['customers.view', 'wallet.credit'], $changes['after']);
        $this->assertEqualsCanonicalizing(['customers.view', 'wallet.transactions.view'], $changes['before']);

        $this->assertEqualsCanonicalizing(['customers.view', 'wallet.credit'], $staff->fresh()->adminPermissionSlugs());
    }

    public function test_a_revoked_permission_stops_working_on_the_next_request(): void
    {
        $master = $this->master();
        $staff = $this->administrator(['wallet.credit']);
        $customer = $this->customer();

        $this->actingAs($master)->post('/admin/permissions/'.$staff->id, [
            'permissions' => [],
            'reason' => 'This account has finished its funding work for today.',
        ])->assertSessionHas('success');

        $this->assertSame([], $staff->fresh()->adminPermissionSlugs());

        $this->actingAs($staff)->post('/admin/users/'.$customer->id.'/fund-wallet', [
            'amount' => 5000,
            'reason' => 'Posted after the permission was taken away.',
        ])->assertForbidden();

        $this->assertSame(0, $this->balance($customer));
    }

    public function test_a_configured_master_holds_every_permission_without_a_single_grant_row(): void
    {
        $master = $this->master();

        $this->assertSame(0, $master->permissionGrants()->count());
        $this->assertSame(AdminPermissions::slugs(), $master->adminPermissionSlugs());

        foreach (['/admin/users', '/admin/wallet-transactions', '/admin/settings', '/admin/permissions', '/admin/wallet-deposits/held'] as $path) {
            $this->actingAs($master)->get($path)->assertOk();
        }
    }

    public function test_promoting_an_account_opens_a_door_but_grants_it_nothing(): void
    {
        $master = $this->master();
        $promoted = $this->customer();

        $this->actingAs($master)->post('/admin/users/'.$promoted->id.'/admin', ['is_admin' => '1'])
            ->assertSessionHas('success');

        $this->assertTrue((bool) $promoted->fresh()->is_admin);
        $this->assertSame([], $promoted->fresh()->adminPermissionSlugs());
        $this->actingAs($promoted)->get('/admin/users')->assertForbidden();
        $this->assertSame(0, DB::table('admin_permission_grants')->where('user_id', $promoted->id)->count());
    }

    public function test_a_master_admin_cannot_be_demoted_from_the_admin_screen(): void
    {
        $master = $this->master();
        $other = $this->administrator(['admins.grant', 'customers.view']);

        $this->actingAs($other)->post('/admin/users/'.$master->id.'/admin', ['is_admin' => '0'])
            ->assertSessionHas('error');

        $this->assertTrue((bool) $master->fresh()->is_admin, 'A Master Admin was switched off by a website screen.');
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'admin.access_changed',
            'result' => 'denied',
            'admin_user_id' => $other->id,
        ]);
    }

    public function test_a_plain_customer_and_a_guest_reach_nothing_behind_the_admin_door(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get('/admin/users')->assertForbidden();
        $this->actingAs($customer)->post('/admin/users/'.$customer->id.'/fund-wallet', [
            'amount' => 5000,
            'note' => 'A customer posting the funding form directly.',
        ])->assertForbidden();

        $this->assertSame(0, $this->balance($customer));
    }
}
