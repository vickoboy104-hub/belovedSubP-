<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The number a support note, an audit row and a wallet record all point at has to be
 * readable on the screen - and the role beside it has to be the role the application
 * actually enforces, not a nicer word for it.
 *
 * These tests pin the three labels the owner asked for (USER, ADMIN, MASTER ADMIN),
 * the search by that number, and the rule that reading any of it moves nothing.
 */
class UserIdentityDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $first = 'Plain', string $last = 'Member'): User
    {
        $user = User::factory()->create([
            'name' => $first.' '.$last,
            'first_name' => $first,
            'last_name' => $last,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user->fresh();
    }

    /**
     * @param  list<string>|null  $permissions
     */
    private function administrator(?array $permissions = null, string $first = 'Desk', string $last = 'Officer'): User
    {
        return $this->promoteAsAdministrator(
            User::factory()->create(['name' => $first.' '.$last, 'first_name' => $first, 'last_name' => $last]),
            $permissions
        );
    }

    public function test_the_customer_list_shows_each_accounts_real_id_and_role(): void
    {
        $viewer = $this->administrator();
        $member = $this->member();

        $html = $this->actingAs($viewer)->get('/admin/users')->assertOk()->getContent();

        $this->assertStringContainsString('#'.$member->id, $html, 'The list showed no user id.');
        $this->assertStringContainsString('User ID', $html);
        $this->assertStringContainsString('USER', $html);
        $this->assertStringContainsString('ADMIN', $html);
        $this->assertStringNotContainsString('Super Admin', $html, 'A role the application does not enforce was displayed.');
    }

    public function test_typing_a_user_id_finds_that_one_account(): void
    {
        $viewer = $this->administrator();
        $wanted = $this->member('Wanted', 'Person');
        $this->member('Other', 'Person');

        $html = $this->actingAs($viewer)->get('/admin/users?search='.$wanted->id)->assertOk()->getContent();

        $this->assertStringContainsString('Wanted Person', $html);
        $this->assertStringNotContainsString('Other Person', $html, 'An id search returned somebody else.');
    }

    public function test_a_customer_profile_shows_the_id_and_the_role_that_matches_the_record(): void
    {
        $viewer = $this->administrator();
        $member = $this->member();

        $html = $this->actingAs($viewer)->get('/admin/users/'.$member->id)->assertOk()->getContent();
        $this->assertStringContainsString('#'.$member->id.' ', $html);
        $this->assertStringContainsString('USER', $html);

        $admin = $this->administrator();
        $html = $this->actingAs($viewer)->get('/admin/users/'.$admin->id)->assertOk()->getContent();
        $this->assertStringContainsString('ADMIN', $html);
        $this->assertStringContainsString('User ID <span class="font-mono">'.$admin->id.'</span>', $html);
    }

    public function test_a_master_admin_is_labelled_master_admin_everywhere_the_role_shows(): void
    {
        $master = $this->administrator();
        config(['admin.master_user_ids' => [(int) $master->id]]);

        $viewer = $this->administrator();

        $this->assertStringContainsString(
            'MASTER ADMIN',
            $this->actingAs($viewer)->get('/admin/users/'.$master->id)->assertOk()->getContent()
        );
        $this->assertStringContainsString(
            'MASTER ADMIN',
            $this->actingAs($viewer)->get('/admin/users?search='.$master->id)->assertOk()->getContent()
        );
        $this->assertSame('MASTER ADMIN', $master->fresh()->accountRoleLabel());
    }

    public function test_an_ordinary_administrator_is_not_labelled_master_anywhere(): void
    {
        config(['admin.master_user_ids' => []]);
        $admin = $this->administrator();

        $html = $this->actingAs($admin)->get('/admin/users/'.$admin->id)->assertOk()->getContent();

        $this->assertStringContainsString('ADMIN', $html);
        $this->assertStringNotContainsString('MASTER ADMIN', $html);
    }

    public function test_the_administrators_page_shows_which_permissions_each_account_holds(): void
    {
        $total = count(AdminPermissions::slugs());
        $narrow = $this->administrator(['customers.view'], 'Narrow', 'Reader');
        $wide = $this->administrator(['customers.view', 'wallet.credit'], 'Wide', 'Clerk');

        $owner = $this->administrator();
        // Naming an audit owner also makes that account a Master by the documented
        // fallback in config/admin.php, so its row reads "Every permission".
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $html = $this->actingAs($owner)->get('/admin/administrators')->assertOk()->getContent();

        $this->assertStringContainsString('1 of '.$total.' permissions', $html);
        $this->assertStringContainsString('2 of '.$total.' permissions', $html);
        $this->assertStringContainsString('Every permission · Audit owner', $html);
        $this->assertStringContainsString('customers.view, wallet.credit', $html, 'The permissions themselves are not listed.');
        $this->assertStringContainsString('#'.$narrow->id, $html);
        $this->assertStringContainsString('#'.$wide->id, $html);
        $this->assertStringNotContainsString('Full admin area', $html, 'The page still describes access as all-or-nothing.');
    }

    public function test_an_account_with_nothing_granted_is_shown_as_having_nothing(): void
    {
        $total = count(AdminPermissions::slugs());
        $owner = $this->administrator();
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        $promoted = User::factory()->create(['name' => 'Fresh Admin', 'first_name' => 'Fresh', 'last_name' => 'Admin']);
        $promoted->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $promoted->permissionGrants()->delete();

        $html = $this->actingAs($owner)->get('/admin/administrators')->assertOk()->getContent();

        $this->assertStringContainsString('0 of '.$total.' permissions', $html);
        $this->assertStringContainsString('Nothing granted to this account yet.', $html);
    }

    public function test_a_customer_sees_their_own_user_id_on_their_profile(): void
    {
        $member = $this->member();

        $html = $this->actingAs($member)->get('/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Your User ID', $html);
        $this->assertStringContainsString('value="'.$member->id.'"', $html);
    }

    public function test_reading_every_identity_screen_moves_no_money(): void
    {
        $viewer = $this->administrator();
        $member = $this->member();
        Wallet::query()->where('user_id', $member->id)->update(['balance' => 12_345]);

        $this->actingAs($viewer)->get('/admin/users')->assertOk();
        $this->actingAs($viewer)->get('/admin/users/'.$member->id)->assertOk();
        $this->actingAs($member)->get('/profile')->assertOk();

        $this->assertSame(12_345, (int) Wallet::query()->where('user_id', $member->id)->value('balance'));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }
}
