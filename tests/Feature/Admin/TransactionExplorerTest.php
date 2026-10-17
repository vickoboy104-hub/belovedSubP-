<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AdminActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The explorer answers "where did this money go" without opening the ledger in a
 * browser. Two things are proved here that the old page only claimed: every filter
 * is answered by the database rather than after the rows arrive, and a screen that
 * exists to look at money cannot move it.
 */
class TransactionExplorerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name = 'Desk Admin'): User
    {
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

    private function customer(string $name, int $balanceKobo = 100_000): User
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $user = User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'email_verified_at' => now(),
        ]);

        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => $balanceKobo]);

        return $user->fresh();
    }

    private function row(User $user, array $attributes = []): WalletTransaction
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $row = WalletTransaction::create(array_merge([
            'wallet_id' => $user->wallet->id,
            'type' => 'debit',
            'amount' => 50_000,
            'reference' => 'TEST-'.strtoupper(substr(uniqid(), -7)),
            'status' => 'success',
            'channel' => 'purchase',
            'description' => 'Airtime test',
        ], $attributes));

        // created_at is deliberately not fillable on the ledger model, so a test
        // that needs an old row has to stamp it after the insert.
        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt])->save();
        }

        return $row;
    }

    public function test_only_the_requested_page_of_rows_ever_reaches_the_browser(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Aisha Bello');

        for ($i = 1; $i <= 45; $i++) {
            $this->row($customer, ['description' => 'Row '.$i]);
        }

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?per_page=20')->assertOk()->getContent();

        $this->assertSame(20, substr_count($html, 'records-row'), 'The page rendered more rows than it was given.');
        $this->assertStringContainsString('20 of 45 matching rows', str_replace("\n", ' ', strip_tags($html)));
    }

    public function test_a_wide_page_request_is_clamped_instead_of_loading_the_ledger(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Chidi Okonkwo');

        for ($i = 1; $i <= 30; $i++) {
            $this->row($customer);
        }

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?per_page=5000')->assertOk()->getContent();

        $this->assertLessThanOrEqual(100, substr_count($html, 'records-row'), 'A URL parameter asked for the whole ledger and got it.');
    }

    public function test_every_identity_filter_narrows_at_the_database(): void
    {
        $admin = $this->admin();
        $aisha = $this->customer('Aisha Bello');
        $chidi = $this->customer('Chidi Okonkwo');

        $mine = $this->row($aisha, ['reference' => 'MINE-ONLY-1', 'channel' => 'refund', 'status' => 'failed', 'created_at' => now()->subDays(40)]);
        $theirs = $this->row($chidi, ['reference' => 'THEIRS-ONLY-2']);

        $byUser = $this->actingAs($admin)->get('/admin/wallet-transactions?user_id='.$aisha->id)->getContent();
        $this->assertStringContainsString('MINE-ONLY-1', $byUser);
        $this->assertStringNotContainsString('THEIRS-ONLY-2', $byUser);

        $byReference = $this->actingAs($admin)->get('/admin/wallet-transactions?reference=THEIRS-ONLY-2')->getContent();
        $this->assertStringContainsString('THEIRS-ONLY-2', $byReference);
        $this->assertStringNotContainsString('MINE-ONLY-1', $byReference);

        $byTxnId = $this->actingAs($admin)->get('/admin/wallet-transactions?txn_id='.$mine->id)->getContent();
        $this->assertStringContainsString('MINE-ONLY-1', $byTxnId);
        $this->assertStringNotContainsString('THEIRS-ONLY-2', $byTxnId);

        $byChannelAndStatus = $this->actingAs($admin)->get('/admin/wallet-transactions?channel=refund&status=failed')->getContent();
        $this->assertStringContainsString('MINE-ONLY-1', $byChannelAndStatus);

        // The old row is outside a window that ends yesterday.
        $recent = $this->actingAs($admin)->get('/admin/wallet-transactions?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString())->getContent();
        $this->assertStringNotContainsString('MINE-ONLY-1', $recent);
        $this->assertStringContainsString('THEIRS-ONLY-2', $recent);
    }

    public function test_an_order_is_linked_only_through_the_reference_it_actually_debited(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Fatima Yusuf');
        $service = \App\Models\Service::create(['name' => 'Airtime', 'slug' => 'airtime-test', 'amount' => 50000]);

        $paid = $this->row($customer, ['reference' => 'ORDER-PAID-REF']);
        $unrelated = $this->row($customer, ['reference' => 'ORDER-OTHER-REF']);

        $order = Order::create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'customer_ref' => '08031234567',
            'amount' => 50_000,
            'status' => 'success',
            'provider_reference' => 'ORDER-PAID-REF',
        ]);

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?order_id='.$order->id)->getContent();
        $this->assertStringContainsString('ORDER-PAID-REF', $html);
        $this->assertStringNotContainsString('ORDER-OTHER-REF', $html, 'Rows that are not this order were listed as if they were.');

        $bare = Order::create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'customer_ref' => '08031234567',
            'amount' => 50_000,
            'status' => 'pending',
        ]);

        $noneHtml = $this->actingAs($admin)->get('/admin/wallet-transactions?order_id='.$bare->id)->getContent();
        $this->assertStringContainsString('has no wallet reference recorded', strip_tags($noneHtml));
        $this->assertStringNotContainsString('ORDER-PAID-REF', $noneHtml);
    }

    public function test_the_administrator_filter_reads_the_trail_not_a_column_on_the_ledger(): void
    {
        $worker = $this->admin('Desk Admin');
        $other = $this->admin('Night Admin');
        $customer = $this->customer('Musa Ibrahim');

        $handRow = $this->row($customer, [
            'type' => 'credit',
            'reference' => 'ADMINFUND-HAND-1',
            'channel' => 'admin_manual_credit',
            'meta' => ['admin_user_id' => $worker->id],
        ]);
        $this->row($customer, ['reference' => 'GATEWAY-ROW-1', 'channel' => 'flutterwave']);

        // The trail entry a hand credit leaves is what makes it findable.
        $this->actingAs($worker);
        app(AdminActivityRecorder::class)->success('wallet', 'wallet.fund', 'Credited by hand.', [
            'actor' => $worker,
            'reference' => $handRow->reference,
        ]);

        $found = $this->actingAs($other)
            ->get('/admin/wallet-transactions?admin='.$worker->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ADMINFUND-HAND-1', $found);
        $this->assertStringNotContainsString('GATEWAY-ROW-1', $found);
    }

    public function test_phone_digits_in_a_description_are_masked_on_the_board(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Bola Ade');
        $this->row($customer, ['reference' => 'PHONE-MASK-1', 'description' => 'Airtime - 08031234567']);

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?reference=PHONE-MASK-1')->assertOk()->getContent();

        $this->assertStringNotContainsString('08031234567', $html, 'A card face leaked a whole phone number.');
        $this->assertStringContainsString('*******4567', $html, 'The masked phone does not show the last four digits.');
        $this->assertStringContainsString('data-copy-text="PHONE-MASK-1"', $html, 'The reference cannot be copied.');
    }

    public function test_the_trail_link_only_appears_for_the_named_owner(): void
    {
        $admin = $this->admin('Desk Admin');
        $customer = $this->customer('Uche Nnamdi');
        $this->row($customer, ['reference' => 'LINK-SHOWN-1']);

        $plain = $this->actingAs($admin)->get('/admin/wallet-transactions?reference=LINK-SHOWN-1')->assertOk()->getContent();
        $this->assertStringNotContainsString('Trail for this reference', $plain);

        config(['audit.owner_user_ids' => [(int) $admin->id]]);

        $owned = $this->actingAs($admin)->get('/admin/wallet-transactions?reference=LINK-SHOWN-1')->assertOk()->getContent();
        $this->assertStringContainsString('Trail for this reference', $owned);
    }

    public function test_browsing_the_explorer_moves_no_money_and_writes_no_ledger_row(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Ngozi Eze', 250_000);
        $this->row($customer, ['reference' => 'READ-ONLY-1', 'status' => 'failed']);

        $rows = WalletTransaction::query()->count();

        foreach (['', '?status=failed', '?type=debit', '?reference=READ-ONLY-1', '?user_id='.$customer->id, '?sort=highest&per_page=10'] as $suffix) {
            $this->actingAs($admin)->get('/admin/wallet-transactions'.$suffix)->assertOk();
        }

        $this->assertSame(250_000, (int) Wallet::query()->where('user_id', $customer->id)->value('balance'));
        $this->assertSame($rows, WalletTransaction::query()->count(), 'Looking at the ledger wrote to it.');
        $this->assertSame(0, AdminActivityLog::query()->where('result', '!=', 'success')->count());
    }

    public function test_filters_survive_the_tables_own_search_and_page_size(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Sadiq Lawal');
        $this->row($customer, ['reference' => 'KEEP-FILTER-1', 'channel' => 'refund']);

        $html = $this->actingAs($admin)->get('/admin/wallet-transactions?channel=refund&page=1')->getContent();

        $this->assertStringContainsString('type="hidden" name="channel"', $html);
        $this->assertStringContainsString('value="refund"', $html);
        $this->assertStringNotContainsString('type="hidden" name="sort"', $html, 'A default was posted as a hidden filter.');
    }

    public function test_the_shared_pager_replaces_the_framework_one_everywhere(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Amina Yakubu');

        for ($i = 1; $i <= 25; $i++) {
            $this->row($customer, ['description' => 'Page row '.$i]);
        }

        $explorer = $this->actingAs($admin)->get('/admin/wallet-transactions?per_page=10')->assertOk()->getContent();
        $this->assertStringContainsString('class="pager"', $explorer);
        $this->assertStringContainsString(
            'Showing 1–10 of 25 results',
            preg_replace('/\s+/', ' ', strip_tags($explorer)),
            'The pager did not state how many rows it is paging through.'
        );
        $this->assertStringContainsString('aria-label="Go to page 2"', $explorer, 'Page two cannot be reached.');

        foreach (['/admin/users', '/admin/orders', '/wallet'] as $url) {
            $html = $this->actingAs($admin)->get($url)->getContent();

            if (str_contains($html, 'Showing')) {
                $this->assertStringContainsString('class="pager"', $html, $url.' still uses the framework pager.');
            }
        }
    }

    public function test_a_non_admin_cannot_reach_any_of_it(): void
    {
        $member = $this->customer('Plain Member');

        foreach (['', '?user_id=1', '?reference=x'] as $suffix) {
            $this->actingAs($member)->get('/admin/wallet-transactions'.$suffix)->assertForbidden();
        }

        $this->assertStringNotContainsString('Plain Member', AdminActivityLog::query()->pluck('description')->implode(' '));
    }
}
