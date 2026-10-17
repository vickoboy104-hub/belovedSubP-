<?php

namespace Tests\Feature;

use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdminPermissions;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A button a person cannot press is not neutral. It reads like an offer: an admin
 * taps it, is refused, and cannot tell whether the refusal is a bug, a shortage of
 * permission, or the site telling them the customer is not theirs to touch. So the
 * controls that require a grant this account does not hold are hidden, and the check
 * that decides stays in the middleware, where typing a URL cannot get around it.
 *
 * These tests hold both halves of that sentence, because each one is the failure mode
 * of the other: a narrowed admin must not be shown a control that can only refuse
 * them, an admin who does hold the grant must still be shown it (a mistyped slug in a
 * guard hides a working tool from everybody, silently), and hiding must never be the
 * only thing standing between a wallet and a stranger.
 */
class AdminUnavailableControlsAreHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $keep */
    private function adminHoldingOnly(array $keep): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        // Production keeps its answers in this table, so a narrower admin is made by
        // taking grants away from the same baseline every existing admin was given.
        $admin->permissionGrants()->whereNotIn('permission', $keep)->delete();

        return $admin->fresh();
    }

    private function customer(): User
    {
        $user = User::factory()->create(['first_name' => 'Nneka', 'last_name' => 'Obi', 'email' => 'nneka@belovedsubp.com']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user;
    }

    public function test_every_permission_guard_written_in_an_admin_screen_names_a_real_permission(): void
    {
        $files = [];
        $directory = new RecursiveDirectoryIterator(__DIR__.'/../../resources/views/admin', FilesystemIterator::SKIP_DOTS);

        foreach (new RecursiveIteratorIterator($directory) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);
        $this->assertGreaterThan(20, count($files), 'The guard found almost no admin screens to read, so it proves nothing.');

        $slugs = AdminPermissions::slugs();
        $unknown = [];
        $checked = 0;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/@can(?:any)?\(\s*(?:'([^']+)'|\[([^\]]*)\])/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $candidates = $match[1] !== ''
                    ? [$match[1]]
                    : array_map(trim(...), str_getcsv($match[2], "'", '', '\\'));

                foreach ($candidates as $slug) {
                    $slug = trim($slug, " '\t,");
                    if ($slug === '') {
                        continue;
                    }

                    $checked++;

                    if (!in_array($slug, $slugs, true) && $slug !== AdminPermissions::MASTER_PERMISSION) {
                        $unknown[] = basename($file).': '.$slug;
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'Too few permission guards were read from these screens.');
        $this->assertSame(
            [],
            array_values(array_unique($unknown)),
            'A guard on a permission that does not exist hides its control from every administrator for ever, silently.'
        );

        // The same mistake on a route is worse than on a screen: the middleware refuses
        // an unknown name outright, so the whole feature is shut to everybody, Master
        // included, with nothing on the page to explain it.
        $routes = (string) file_get_contents(__DIR__.'/../../routes/web.php');
        $routeSums = [];
        preg_match_all("/can_admin:([a-z0-9_.,]+)/i", $routes, $guardMatches);

        foreach ($guardMatches[1] as $list) {
            foreach (explode(',', $list) as $slug) {
                $slug = trim($slug, " '\t[]\\");
                if ($slug === '') {
                    continue;
                }

                $routeSums[] = $slug;
            }
        }

        $this->assertGreaterThan(20, count($routeSums), 'No administrator route guards were read.');

        $badRoutes = array_values(array_unique(array_filter(
            $routeSums,
            static fn (string $slug): bool => !in_array($slug, $slugs, true)
                && $slug !== AdminPermissions::MASTER_PERMISSION
        )));

        $this->assertSame([], $badRoutes, 'An administrator route asks for a permission that cannot be granted.');
    }

    public function test_an_admin_without_the_money_grants_is_not_offered_the_controls_that_can_only_refuse_them(): void
    {
        $reader = $this->adminHoldingOnly(['customers.view']);
        $customer = $this->customer();

        $page = $this->actingAs($reader)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        // Reading the customer is the job this account was given, and it survives.
        $this->assertStringContainsString('nneka@belovedsubp.com', $page);

        // Marked on the form targets rather than the button words: the layout prints
        // a customer-side "Fund Wallet" link on every admin page, and a label check
        // would read that as an unhidden control.
        foreach (['/discount', '/reset-password', '/fund-wallet', '/admin'] as $suffix) {
            $target = '/admin/users/'.$customer->id.$suffix;
            $this->assertStringNotContainsString($target, $page, 'The customer list offered a form this account cannot use: '.$target);
        }
    }

    public function test_an_admin_who_holds_the_grants_is_still_shown_every_control_they_are_owed(): void
    {
        $admin = $this->adminHoldingOnly(AdminPermissions::slugs());
        $customer = $this->customer();

        $page = $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        foreach (['/discount', '/reset-password', '/fund-wallet', '/admin'] as $suffix) {
            $target = '/admin/users/'.$customer->id.$suffix;
            $this->assertStringContainsString($target, $page, 'A granted control was hidden from the person entitled to it: '.$target);
        }
    }

    public function test_hiding_a_control_does_not_move_the_decision_out_of_the_middleware(): void
    {
        $reader = $this->adminHoldingOnly(['customers.view']);
        $customer = $this->customer();

        // The refusal still has to be the server's, because nothing stops a person
        // from replaying a saved request for a form they were never shown.
        $this->actingAs($reader)->post(route('admin.users.fund-wallet', $customer), [
            'amount' => 500,
            'note' => 'Pressing a button the page never showed me.',
        ])->assertForbidden();

        $this->actingAs($reader)->post(route('admin.users.admin', $customer), ['is_admin' => '1'])
            ->assertForbidden();

        $this->actingAs($reader)->post(route('admin.users.reset-password', $customer))->assertForbidden();

        $this->actingAs($reader)->post(route('admin.users.discount', $customer), ['discount_percent' => 50])
            ->assertForbidden();

        $this->assertSame(0, (int) Wallet::query()->where('user_id', $customer->id)->value('balance'));

        // Four refusals, one line: the denial trail is deliberately folded per action,
        // result and address so a hammering cannot fill the log. The first refusal is
        // still written, which is what makes repeated reaching for a denied permission
        // something the owner can see.
        $this->assertSame(
            1,
            DB::table('admin_activity_logs')->where('action', 'admin.permission_denied')->count(),
            'Not one of four server-side refusals reached the trail.'
        );
    }

    public function test_the_customer_detail_page_keeps_its_money_and_permission_tools_off_a_reader(): void
    {
        $reader = $this->adminHoldingOnly(['customers.view']);
        $customer = $this->customer();

        $page = $this->actingAs($reader)
            ->get(route('admin.users.show', $customer))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('nneka@belovedsubp.com', $page);
        $this->assertStringNotContainsString('Wallet Adjustment', $page);
        $this->assertStringNotContainsString('Save Details', $page);
        $this->assertStringNotContainsString('Admin Controls', $page);
        $this->assertStringNotContainsString('settled by hand', $page);

        $full = $this->adminHoldingOnly(AdminPermissions::slugs());
        $granted = $this->actingAs($full)
            ->get(route('admin.users.show', $customer))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Wallet Adjustment', $granted);
        $this->assertStringContainsString('Save Details', $granted);
    }

    public function test_the_held_deposit_board_shows_the_money_and_hides_the_verdict_from_a_reader(): void
    {
        $customer = $this->customer();
        FlutterwaveHeldDeposit::query()->create([
            'charge_token' => '9031',
            'flutterwave_charge_id' => '9031',
            'virtual_account_number' => '07777777777',
            'amount_kobo' => 300_000,
            'currency' => 'NGN',
            'channel' => 'flutterwave_virtual_account',
            'arrived_via' => 'webhook',
            'reason' => 'no_matching_customer',
            'holder_user_ids' => [$customer->id],
            'payload' => ['id' => 9031, 'charged_amount' => 3000.00],
            'status' => 'held',
        ]);

        $reader = $this->adminHoldingOnly(['wallet.held.view', 'customers.view']);
        $page = $this->actingAs($reader)
            ->get(route('admin.wallet.deposits.held'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('3,000.00', $page);
        $this->assertStringNotContainsString('Credit this customer', $page);
        $this->assertStringNotContainsString('Keep the record, credit nobody', $page);

        $held = FlutterwaveHeldDeposit::query()->sole();

        $this->actingAs($reader)
            ->post(route('admin.wallet.deposits.held.credit', $held), [
                'user_id' => $customer->id,
                'note' => 'Releasing a deposit from an account that was never shown the button.',
            ])
            ->assertForbidden();

        $this->assertSame('held', $held->fresh()->status);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $customer->id)->value('balance'));

        $resolver = $this->adminHoldingOnly(['wallet.held.view', 'wallet.held.resolve', 'customers.view']);
        $board = $this->actingAs($resolver)->get(route('admin.wallet.deposits.held'))->assertOk()->getContent();
        $this->assertStringContainsString('Credit this customer', $board);
    }

    public function test_the_site_wide_controls_follow_the_same_rule_as_the_pages_under_review(): void
    {
        $reader = $this->adminHoldingOnly(['customers.view', 'orders.manual.view', 'wallet.history.view']);

        $dashboard = $this->actingAs($reader)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Reset Totals', $dashboard);

        $settings = $this->actingAs($this->adminHoldingOnly(['settings.change']))
            ->get(route('admin.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Sync Latest GSUBZ Prices', $settings);

        $pricing = $this->actingAs($this->adminHoldingOnly(['prices.edit']))
            ->get(route('admin.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Sync Latest GSUBZ Prices', $pricing);
    }
}
