<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider prints a job's history the same way on every screen: a record
 * count beside a search box and a page-size select, one row per request, an em
 * dash where a value has not arrived, and the whole row behind a "+" on a phone.
 * These tests pin that style down so a later edit cannot quietly drop the tools
 * a customer uses to find one payment among many.
 */
class TransactionHistoryPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function orderFor(User $user, array $attributes): Order
    {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'service_id' => Service::query()->firstOrCreate(
                ['slug' => 'nin_slip_print'],
                ['name' => 'Print NIN Slip']
            )->id,
            'customer_ref' => 'REF-ONE',
            'amount' => 100_00,
            'provider' => 'manual',
            'provider_reference' => '',
            'status' => 'pending',
            'meta' => ['type' => 'manual_service', 'manual_service_title' => 'Print NIN Slip'],
        ], $attributes));
    }

    public function test_the_history_block_opens_with_a_record_count_and_a_page_size_select(): void
    {
        $user = $this->memberWithBalance(5_000_00);
        $this->orderFor($user, ['customer_ref' => 'BTX947E60001020']);
        $this->orderFor($user, ['customer_ref' => '12345678901']);

        $this->actingAs($user)
            ->get('/vtu/orders')
            ->assertOk()
            ->assertSee('Total Record(2)')
            ->assertSee('page 1 of 1')
            ->assertSee('name="q"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('Search by Tracking ID, NIN or BVN', false);
    }

    public function test_the_search_box_finds_the_request_the_customer_paid_for(): void
    {
        $user = $this->memberWithBalance(5_000_00);
        $this->orderFor($user, ['customer_ref' => 'BTX947E60001020']);
        $this->orderFor($user, ['customer_ref' => '12345678901']);

        $this->actingAs($user)
            ->get('/vtu/orders?q=BTX947E60001020')
            ->assertOk()
            ->assertSee('Total Record(1)')
            ->assertSee('BTX947E60001020')
            ->assertDontSee('12345678901', false);
    }

    public function test_the_page_size_select_is_the_only_way_to_change_rows_per_page(): void
    {
        $user = $this->memberWithBalance(5_000_00);
        foreach (range(1, 12) as $n) {
            $this->orderFor($user, ['customer_ref' => 'REF-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)])
                ->forceFill(['created_at' => now()->subMinutes(12 - $n)])
                ->save();
        }

        $html = $this->actingAs($user)->get('/vtu/orders?per_page=10')
            ->assertOk()
            ->getContent();

        // Twelve requests exist, ten arrive, and the two oldest wait for page
        // two - so the count has to speak for the whole ledger, not this page.
        $this->assertStringContainsString('Total Record(12)', $html);
        $this->assertStringContainsString('page 1 of 2', $html);
        $this->assertStringContainsString('REF-012', $html);
        $this->assertStringContainsString('REF-003', $html);
        $this->assertStringNotContainsString('REF-001', $html);
    }

    public function test_an_unknown_balance_prints_a_dash_instead_of_a_made_up_figure(): void
    {
        $user = $this->memberWithBalance(5_000_00);
        $this->orderFor($user, ['customer_ref' => 'BTX947E60001020']);

        $this->actingAs($user)
            ->get('/vtu/orders')
            ->assertOk()
            ->assertSee('—', false)
            ->assertDontSee('₦0.00', false);
    }

    public function test_a_service_page_lists_only_that_service_s_own_history(): void
    {
        $user = $this->memberWithBalance(500_000);

        $this->actingAs($user)->post('/vtu/manual/ipe_clearance', [
            'ipe_type' => 'new_enrollment',
            'tracking_id' => 'BTX947E60001020',
        ])->assertRedirect();

        $this->actingAs($user)->post('/vtu/manual/bvn_print', [
            'bvn' => '22334455667',
        ])->assertRedirect();

        $this->actingAs($user)
            ->get('/vtu/manual/ipe_clearance')
            ->assertOk()
            ->assertSee('Total Record(1)')
            ->assertSee('BTX947E60001020')
            ->assertDontSee('22334455667', false);
    }

    public function test_the_service_page_states_its_price_as_a_cost_line_and_keeps_previous_beside_submit(): void
    {
        $user = $this->memberWithBalance(500_000);

        $this->actingAs($user)
            ->get('/vtu/manual/nin_personalization')
            ->assertOk()
            ->assertSee('This service will cost you', false)
            ->assertSee('Tracking ID')
            ->assertSee('Previous')
            ->assertSee('Submit');
    }
}
