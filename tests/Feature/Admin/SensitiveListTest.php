<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three boards print many customers at once: the directory, the VTU order board and
 * the manual identity queue. A screen full of other people's numbers is the one copy
 * of that data an admin never asked for, so the digits keep their last four here and
 * stay whole on the single record the agent actually opens.
 */
class SensitiveListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create([
            'first_name' => 'Desk',
            'last_name' => 'Officer',
            'name' => 'Desk Officer',
            'email' => 'desk-officer@example.test',
            'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function customer(string $name, string $phone, ?string $account = null): User
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $user = User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'email_verified_at' => now(),
        ]);

        if ($account !== null) {
            $user->forceFill([
                'virtual_account_number' => $account,
                'virtual_account_bank' => 'Monitor JP MFB',
                'virtual_account_name' => strtoupper($name),
            ])->save();
        }

        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 250_000]);

        return $user->fresh();
    }

    private function order(User $customer, array $attributes = []): Order
    {
        $service = \App\Models\Service::create(['slug' => 'mask-test-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT), 'name' => 'Mask Test']);

        return Order::create(array_merge([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'amount' => 10_000,
            'status' => 'success',
        ], $attributes));
    }

    public function test_the_directory_shows_only_the_last_digits_of_a_phone_and_account_number(): void
    {
        $admin = $this->admin();
        $this->customer('Sadiq Lawal', '08034567891', '99012345678');

        $html = $this->actingAs($admin)->get('/admin/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('08034567891', $html, 'The directory printed a whole phone number.');
        $this->assertStringNotContainsString('99012345678', $html, 'The directory printed a whole virtual account number.');
        $this->assertStringContainsString('*******7891', $html);
        $this->assertStringContainsString('*******5678', $html);
    }

    public function test_searching_the_directory_by_the_full_number_still_finds_the_customer(): void
    {
        $admin = $this->admin();
        $this->customer('Amina Yakubu', '07061234567');

        $html = $this->actingAs($admin)->get('/admin/users?search=07061234567')->assertOk()->getContent();

        $this->assertStringContainsString('Amina Yakubu', $html, 'Masking the display stopped the number being searched.');
        // The number comes back whole only in the box the admin typed it into; the
        // row itself still shows four digits.
        $this->assertStringContainsString('*******4567', $html);
    }

    public function test_the_order_board_masks_the_number_it_sold_to_and_copies_the_reference(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Bello Ahmed', '09022223333');

        $this->order($customer, [
            'customer_ref' => '09022223333',
            'provider' => 'GSUBZ',
            'provider_reference' => 'GW-LONG-REFERENCE-20261017-000451',
            'meta' => ['type' => 'airtime', 'phone' => '09022223333', 'plan' => '₦1,000'],
        ]);

        $html = $this->actingAs($admin)->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('09022223333', $html, 'The order board printed the whole number that was topped up.');
        $this->assertStringContainsString('*******3333', $html);
        $this->assertStringContainsString('data-copy-text="GW-LONG-REFERENCE-20261017-000451"', $html, 'The provider reference cannot be copied.');
    }

    public function test_the_manual_queue_masks_the_identity_numbers_on_its_chips(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Chidi Okonkwo', '08155556666');

        $this->order($customer, [
            'customer_ref' => 'NIN-12345678901',
            'amount' => 30_000,
            'status' => 'pending',
            'meta' => [
                'manual_queue' => true,
                'manual_service' => 'nin_validation',
                'manual_service_title' => 'NIN Validation',
                'submitted' => ['nin' => '12345678901', 'full_name' => 'Chidi Okonkwo'],
            ],
        ]);

        $html = $this->actingAs($admin)->get('/admin/manual-orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('12345678901', $html, 'The queue printed a whole NIN.');
        $this->assertStringNotContainsString('08155556666', $html, 'The queue printed a whole phone number.');
        $this->assertStringContainsString('*******8901', $html);
        $this->assertStringContainsString('NIN Validation', $html, 'The queue stopped telling the agent which job this is.');
    }

    public function test_the_identity_number_is_still_whole_on_the_request_the_agent_opens(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Uche Nnamdi', '08098765432');

        $order = $this->order($customer, [
            'customer_ref' => 'NIN-11223344556',
            'amount' => 30_000,
            'status' => 'pending',
            'meta' => [
                'manual_queue' => true,
                'manual_service' => 'nin_validation',
                'manual_service_title' => 'NIN Validation',
                'submitted' => ['nin' => '11223344556'],
            ],
        ]);

        $list = $this->actingAs($admin)->get('/admin/manual-orders')->getContent();
        $this->assertStringNotContainsString('11223344556', $list);

        $detail = $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id)->assertOk()->getContent();
        $this->assertStringContainsString('11223344556', $detail, 'Masking the queue hid the number the job is worked from.');
    }

    public function test_reading_these_boards_moves_no_money_and_changes_no_order(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('Fatima Bello', '07031112222');
        $before = (int) $customer->wallet->balance;

        $order = $this->order($customer, [
            'customer_ref' => '07031112222',
            'amount' => 5_000,
            'status' => 'pending',
            'meta' => ['manual_queue' => true, 'manual_service' => 'nin_validation', 'submitted' => ['nin' => '99887766554']],
        ]);

        foreach (['/admin/users', '/admin/orders', '/admin/manual-orders', '/admin/wallet-transactions'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->assertSame($before, (int) $customer->fresh()->wallet->balance, 'Viewing a board moved a wallet.');
        $this->assertSame('pending', $order->fresh()->status, 'Viewing a board changed an order.');
        $this->assertDatabaseMissing('wallet_transactions', ['wallet_id' => $customer->wallet->id]);
    }

    /**
     * The recorder is the only writer and it masks on the way in, but a row can reach
     * this table behind it - a migration, an import, or anyone holding the database
     * password. The screen that prints those rows applies the same rule on the way out,
     * while keeping the Reference column whole because that is what the trail is
     * searched by and the writer never hides it.
     */
    public function test_a_row_written_behind_the_recorder_is_still_masked_on_the_screen(): void
    {
        $owner = $this->admin();
        config(['audit.owner_user_ids' => [(int) $owner->id]]);

        \Illuminate\Support\Facades\DB::table('admin_activity_logs')->insert([
            'correlation_id' => 'rogue-row-correlation',
            'admin_user_id' => $owner->id,
            'admin_name' => $owner->name,
            'category' => 'wallet',
            'action' => 'adjust-wallet',
            'description' => 'Refund against BVN 12345678901 for the customer',
            'target_type' => Wallet::class,
            'target_id' => '7',
            'reference' => 'FLW-MOCK-VOU-7788112200',
            'result' => 'success',
            'reason' => 'Card 5555 5555 5555 4444 was charged twice',
            'changes' => json_encode([
                'balance' => ['before' => 1000, 'after' => 2000],
                'note' => 'NIN 11223344556 quoted on the call',
            ]),
            'ip_address' => '127.0.0.1',
            'dedupe_key' => 'rogue-row-dedupe',
            'created_at' => now(),
        ]);

        $html = $this->actingAs($owner)->get('/admin/activity-log')->getContent();

        foreach (['12345678901', '5555 5555 5555 4444', '11223344556'] as $figure) {
            $this->assertStringNotContainsString($figure, $html, 'The log screen printed a whole figure: '.$figure);
        }

        $this->assertStringContainsString('*******8901', $html);
        $this->assertStringContainsString('************4444', $html);
        $this->assertStringContainsString('*******4556', $html);
        $this->assertStringContainsString('FLW-MOCK-VOU-7788112200', $html, 'The reference is the search key; hiding it makes the trail unreadable.');
    }
}
