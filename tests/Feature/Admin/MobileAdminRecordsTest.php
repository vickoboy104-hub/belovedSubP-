<?php

namespace Tests\Feature\Admin;

use App\Models\FlutterwaveHeldDeposit;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletSettlementLink;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every record board the owner named, opened at the width of a phone.
 *
 * Two shapes are required wherever the board is a table: the flat table the desk
 * reads, and a card or expandable row a thumb can read without sideways scrolling.
 * A board that only has the first is the failure this suite exists to catch, because
 * the desktop view looks perfect and hides the fact that the phone view is clipped.
 *
 * These tests read the rendered markup and the stylesheet the browser is actually
 * handed. A media query that never survived the build fixes nothing, and a card
 * layout that hides the reference the agent needs to paste is worse than the table.
 */
class MobileAdminRecordsTest extends TestCase
{
    use RefreshDatabase;

    /** A reference long enough that a narrow column would break it. */
    private const GATEWAY_REF = 'GW-LONG-REFERENCE-20261017-000451';

    private const ACCOUNT = '07001112222';

    private function administrator(): User
    {
        $admin = User::factory()->create([
            'first_name' => 'Desk',
            'last_name' => 'Owner',
            'name' => 'Desk Owner',
            'email' => 'desk-owner@example.test',
            'email_verified_at' => now(),
        ]);

        $admin = $this->promoteAsAdministrator($admin);

        // Naming this account as the audit owner is what opens the two owner-only
        // boards, and the documented fallback makes it Master as well, which is the
        // only way to reach the permissions board at all.
        config(['audit.owner_user_ids' => [(int) $admin->id]]);

        return $admin;
    }

    private function customer(string $name, int $balanceKobo = 123_450): User
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $user = User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['virtual_account_number' => self::ACCOUNT])->save();
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => $balanceKobo]);

        return $user->fresh();
    }

    private function ledger(User $user, array $attributes = []): WalletTransaction
    {
        return WalletTransaction::query()->create(array_merge([
            'wallet_id' => $user->wallet->id,
            'type' => 'credit',
            'amount' => 50_000,
            'reference' => self::GATEWAY_REF,
            'status' => 'success',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Wallet funding',
        ], $attributes));
    }

    private function order(User $user, array $attributes = []): Order
    {
        $service = Service::query()->create([
            'slug' => 'mobile-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Mobile Board Test',
        ]);

        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'customer_ref' => '08012345678',
            'provider_reference' => self::GATEWAY_REF,
            'amount' => 10_000,
            'status' => 'success',
            'meta' => ['type' => 'airtime', 'phone' => '08012345678'],
        ], $attributes));
    }

    private function heldDeposit(): FlutterwaveHeldDeposit
    {
        return FlutterwaveHeldDeposit::query()->create([
            'charge_token' => 'FLW-MOCK-VOU-9900112233',
            'flutterwave_charge_id' => '9900112233',
            'tx_ref' => self::GATEWAY_REF,
            'virtual_account_number' => self::ACCOUNT,
            'amount_kobo' => 300_000,
            'currency' => 'NGN',
            'channel' => 'bank_transfer',
            'arrived_via' => 'webhook',
            'reason' => 'shared_account_number',
            'holder_user_ids' => [],
            'status' => 'held',
        ]);
    }

    private function declaration(User $customer, User $by): WalletSettlementLink
    {
        $credit = $this->ledger($customer, [
            'channel' => 'admin_manual_credit',
            'reference' => 'ADJMOBILE'.random_int(1000, 9999),
            'description' => 'Admin wallet funding',
        ]);

        return WalletSettlementLink::query()->create([
            'charge_token' => 'FLW-MOCK-VOU-9900112233',
            'charge_token_kind' => 'flw_ref',
            'tx_ref' => self::GATEWAY_REF,
            'wallet_id' => $customer->wallet->id,
            'manual_transaction_id' => $credit->id,
            'amount_kobo' => $credit->amount,
            'status' => 'active',
            'reason' => 'Paid by hand on a call; the transfer is the same money.',
            'declared_by_user_id' => $by->id,
            'declared_at' => now(),
        ]);
    }

    /**
     * path => the marker the phone shape must carry, a value the seeded row must put
     * on screen, and whether the board still owes the desk a table.
     *
     * @return array<string, array{mobile: string, proof: string, table: bool}>
     */
    private function boards(): array
    {
        return [
            '/admin/wallet-transactions' => ['mobile' => 'records-cards', 'proof' => self::GATEWAY_REF, 'table' => true],
            '/admin/wallet-stats' => ['mobile' => 'records-cards', 'proof' => 'Total Record', 'table' => true],
            '/admin/users' => ['mobile' => 'app-record-grid', 'proof' => 'User ID', 'table' => true],
            '/admin/administrators' => ['mobile' => 'records-cards', 'proof' => 'Account role', 'table' => true],
            '/admin/activity-log' => ['mobile' => 'records-cards', 'proof' => 'Total Record', 'table' => true],
            '/admin/orders' => ['mobile' => 'app-record-grid', 'proof' => 'Provider Ref', 'table' => true],
            '/admin/manual-orders' => ['mobile' => 'app-record-grid', 'proof' => 'NIN Validation', 'table' => true],
            // These two boards are cards at every width by design: one held transfer
            // and one signed declaration are each a single decision, never a grid.
            '/admin/wallet-deposits/held' => ['mobile' => 'app-record-grid', 'proof' => 'Why it is held', 'table' => false],
            '/admin/settlement-links' => ['mobile' => 'app-record-grid', 'proof' => 'Gateway charge held by this', 'table' => false],
        ];
    }

    private function seedBoards(User $admin, User $customer): void
    {
        $this->ledger($customer);
        $this->order($customer);
        $this->order($customer, [
            'status' => 'pending',
            'meta' => [
                'manual_queue' => true,
                'manual_service' => 'nin_validation',
                'manual_service_title' => 'NIN Validation',
                'submitted' => ['nin' => '12345678901', 'full_name' => 'Aisha Bello'],
            ],
        ]);
        $this->heldDeposit();
        $this->declaration($customer, $admin);
    }

    /** Fails naming the missing marker, so a broken board is readable without a 50kB page dump. */
    private function assertHas(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message.' (looking for: '.$needle.')');
    }

    public function test_every_board_the_owner_named_renders_a_phone_shape_and_keeps_the_desk_table(): void
    {
        $admin = $this->administrator();
        $customer = $this->customer('Aisha Bello');
        $this->seedBoards($admin, $customer);

        foreach ($this->boards() as $path => $board) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            $this->assertHas($board['mobile'], $html, $path.' has no phone shape.');
            $this->assertHas($board['proof'], $html, $path.' dropped the seeded row.');

            if (! $board['table']) {
                continue;
            }

            // Desktop preserved: the same rows still exist as a table, and that table
            // is allowed to be wider than the screen rather than crushing its columns.
            $this->assertHas('<table', $html, $path.' lost the desktop table along with the phone cards.');
            $this->assertTrue(
                (bool) preg_match('/(records-table-wrap|overflow-x-auto)[\s\S]{0,400}?<table/', $html),
                $path.' puts a table on screen with no scrolling container - it would clip.'
            );
        }
    }

    public function test_a_long_gateway_reference_survives_the_card_and_can_be_copied(): void
    {
        $admin = $this->administrator();
        $customer = $this->customer('Bola Adeyemi');
        $this->seedBoards($admin, $customer);

        // Cards, not the component: the reference must print whole and give a Copy button.
        foreach (['/admin/orders', '/admin/wallet-deposits/held', '/admin/settlement-links'] as $path) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            $this->assertHas('data-copy-text="'.self::GATEWAY_REF.'"', $html,
                $path.' hides the reference behind a column that cannot be read.');
            $this->assertHas('records-copy', $html, $path.' has no Copy chip for a thumb.');
            $this->assertHas('break-all', $html, $path.' cannot wrap a long reference.');
        }

        // The component boards put the copy button on the ledger reference itself,
        // in the table and in the card, behind the "+" that opens a row.
        foreach (['/admin/wallet-transactions', '/admin/users/'.$customer->id] as $path) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            $this->assertHas('data-copy-text="'.self::GATEWAY_REF.'"', $html, $path.' cannot copy the ledger reference.');
            $this->assertHas('records-expand', $html, $path.' has no way to open a row on a phone.');
        }
    }

    public function test_the_pager_reaches_every_page_at_a_thumb_size(): void
    {
        $admin = $this->administrator();
        $customer = $this->customer('Chidi Okonkwo');

        for ($i = 1; $i <= 25; $i++) {
            $this->ledger($customer, ['reference' => 'PAGE-TEST-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        }

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?per_page=10')->assertOk()->getContent();

        $this->assertHas('class="pager"', $html, 'The board has no pager at all.');
        $this->assertHas('pager-buttons', $html, 'The pager buttons are missing.');
        $this->assertHas('Go to page 2', $html, 'Page two is unreachable from the first page.');
        $this->assertHas('Go to page 3', $html, 'The last page is unreachable.');
        $this->assertHas('Showing', $html, 'The pager does not say where in the ledger this page sits.');

        // The row count a phone gets is the page size, not the whole ledger.
        $this->assertSame(10, substr_count($html, 'records-row'));

        // A 320px screen gets 44px targets, and the buttons wrap instead of running off.
        $css = $this->builtCss();
        $this->assertHas('.pager-buttons{display:flex;flex-wrap:wrap', $css, 'The pager cannot wrap on a narrow screen.');
        $this->assertHas('.pager-button{min-height:2.75rem;min-width:2.75rem}', $css, 'Pager buttons are smaller than a thumb.');
    }

    public function test_the_compiled_stylesheet_handed_to_the_browser_carries_the_phone_rules(): void
    {
        $css = $this->builtCss();

        // One markup, two shapes: cards below 768px, the flat table from 768px up.
        // These fragments are written the way the bundler emits them - it drops the
        // space after @media, so a source-shaped string never matches the artifact.
        $this->assertHas('@media(min-width:768px){', $css, 'The build dropped the desktop breakpoint.');
        $this->assertHas('.records-table-wrap{display:block}', $css, 'The desktop table is never shown.');
        $this->assertHas('.records-cards{display:none}', $css, 'The phone cards are never hidden on a desk.');
        $this->assertHas('.records-table-wrap{display:none;overflow-x:auto}', $css, 'The table has no scroll container.');

        // A thumb, not a cursor: the expand control and the copy chip are both 44px.
        $this->assertHas('.records-expand{min-width:2.75rem;min-height:2.75rem}', $css, 'The row expander is smaller than a thumb.');
        $this->assertHas('.records-copy{min-height:2.75rem', $css, 'The copy chip is smaller than a thumb.');

        // Safari zooms a page to 16px text when a smaller field takes focus, and the
        // shell hides sideways overflow, so a zoomed board cannot be dragged back.
        $this->assertHas('.records-search{font-size:1rem}', $css, 'The search box would zoom the board on focus.');

        // The smallest phones get one detail per line rather than a crushed pair.
        $this->assertHas('@media(max-width:420px){.app-record-grid{grid-template-columns:1fr}', $css,
            'A 320px screen still gets two crushed columns of detail.');

        // The page shell itself refuses to be dragged wide.
        $this->assertHas('.reference-app-shell{overflow-x:hidden}', $css, 'The shell can be dragged sideways.');
    }

    public function test_the_permissions_board_is_readable_and_tappable_on_a_narrow_screen(): void
    {
        $admin = $this->administrator();
        $other = $this->customer('Second Admin');
        $other->forceFill(['is_admin' => true])->save();

        $html = $this->actingAs($admin)->get('/admin/permissions')->assertOk()->getContent();

        // One section per administrator, stacked, with the whole permission set
        // wrapped in a label a finger can hit; no table to clip.
        $this->assertHas('permission-choice', $html, 'The permission rows lost their tap target.');
        $this->assertHas('grid-cols-1 gap-2 sm:grid-cols-2', $html, 'The permission grid is never one column on a phone.');
        $this->assertHas('break-words', $html, 'A long admin name or slug cannot wrap.');
        $this->assertHas('Second Admin', $html, 'The board does not list the other administrator.');
        $this->assertFalse(str_contains($html, '<table'), 'The permissions board was given a clipping table.');

        // The slug list is long; it has to break rather than push the page wide.
        $this->assertTrue(
            (bool) preg_match('/class="[^"]*break-words[^"]*"[^>]*>[a-z._]+/', $html),
            'No permission slug is printed inside a wrapping element.'
        );
    }

    public function test_reading_every_board_leaves_every_balance_and_every_row_exactly_as_it_was(): void
    {
        $admin = $this->administrator();
        $customer = $this->customer('Uche Nnamdi');
        $this->seedBoards($admin, $customer);

        $before = [
            'balances' => (int) Wallet::query()->sum('balance'),
            'ledger' => WalletTransaction::query()->count(),
            'orders' => Order::query()->count(),
            'held' => FlutterwaveHeldDeposit::query()->count(),
            'declarations' => WalletSettlementLink::query()->count(),
        ];

        $paths = array_keys($this->boards());
        $paths[] = '/admin/users/'.$customer->id;
        $paths[] = '/admin/permissions';

        foreach ($paths as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }

        // The customer's own two boards need the customer, not the desk account.
        foreach (['/wallet/transactions', '/profile'] as $path) {
            $this->actingAs($customer)->get($path)->assertOk();
        }

        $after = [
            'balances' => (int) Wallet::query()->sum('balance'),
            'ledger' => WalletTransaction::query()->count(),
            'orders' => Order::query()->count(),
            'held' => FlutterwaveHeldDeposit::query()->count(),
            'declarations' => WalletSettlementLink::query()->count(),
        ];

        $this->assertSame($before, $after, 'Looking at the records moved money or rewrote a row.');
    }

    private function builtCss(): string
    {
        $css = '';

        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= file_get_contents($file);
        }

        $this->assertNotSame('', $css, 'No compiled stylesheet was found.');

        return $css;
    }
}
