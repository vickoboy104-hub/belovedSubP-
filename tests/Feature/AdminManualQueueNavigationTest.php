<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An admin works the queue one request at a time, so the page has to answer
 * "what is waiting, what do I need to run the job, what is next" without
 * sending them back through the list every time.
 */
class AdminManualQueueNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_the_waiting_tab_shows_the_details_needed_to_run_the_job(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $bvnRequest = $this->submitManualRequest($this->memberWithBalance(500_000), 'bvn_print', [
            'bvn' => '12345678901',
        ]);
        $ipeRequest = $this->submitManualRequest($this->memberWithBalance(500_000), 'ipe_clearance', [
            'tracking_id' => 'ABCDEFGHIJKLMNO',
            'ipe_type' => 'new_enrollment',
            'phone' => '08012345678',
        ]);

        $this->actingAs($admin)
            ->get('/admin/manual-orders')
            ->assertOk()
            ->assertSee('12345678901')
            ->assertSee('ABCDEFGHIJKLMNO')
            ->assertSee('Waiting (2)')
            ->assertSee('Completed (0)');

        // The collected value is searchable, so the admin can find a request by NIN or BVN.
        $this->actingAs($admin)
            ->get('/admin/manual-orders?status=pending&search=12345678901')
            ->assertOk()
            ->assertSee('/admin/manual-orders/'.$bvnRequest->id, false)
            ->assertDontSee('/admin/manual-orders/'.$ipeRequest->id, false);
    }

    public function test_a_completed_request_only_appears_in_its_own_tab(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $done = $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901']);
        $waiting = $this->submitManualRequest($this->memberWithBalance(500_000), 'ipe_clearance', [
            'tracking_id' => 'ABCDEFGHIJKLMNO',
            'ipe_type' => 'new_enrollment',
            'phone' => '08012345678',
        ]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$done->id.'/fulfil', [
            'result_text' => 'Slip attached to your receipt.',
        ]);

        $this->actingAs($admin)->get('/admin/manual-orders?status=success')
            ->assertOk()
            ->assertSee('/admin/manual-orders/'.$done->id, false)
            ->assertDontSee('/admin/manual-orders/'.$waiting->id, false);

        $this->actingAs($admin)->get('/admin/manual-orders?status=pending')
            ->assertOk()
            ->assertSee('/admin/manual-orders/'.$waiting->id, false)
            ->assertDontSee('/admin/manual-orders/'.$done->id, false);
    }

    public function test_publishing_a_result_opens_the_next_waiting_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $first = $this->submitManualRequest($this->memberWithBalance(500_000), 'bvn_print', ['bvn' => '12345678901']);
        $second = $this->submitManualRequest($this->memberWithBalance(500_000), 'nin_delink', [
            'nin' => '23456789012',
            'delink_target' => 'phone',
            'delink_value' => '08098765432',
            'phone' => '08012345678',
        ]);

        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$first->id.'/fulfil', ['result_text' => 'Done.'])
            ->assertRedirect(route('admin.manual-orders.show', $second->id));

        // The last one sends them back to the list instead of an empty queue.
        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$second->id.'/fulfil', ['result_text' => 'Done too.'])
            ->assertRedirect(route('admin.manual-orders.index', ['status' => 'success']));

        $this->assertSame(0, $this->manualServices()->waitingCount());
    }

    public function test_the_admin_page_navigation_points_at_the_waiting_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->submitManualRequest($this->memberWithBalance(500_000), 'bvn_print', ['bvn' => '12345678901']);
        $this->submitManualRequest($this->memberWithBalance(500_000), 'bvn_print', ['bvn' => '98765432101']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Manual Requests')
            ->assertSee('2 waiting')
            ->assertSee(route('admin.manual-orders.index', ['status' => 'pending']), false);
    }

    public function test_a_request_that_is_not_in_the_queue_is_not_editable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'service_id' => $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901'])->service_id,
            'customer_ref' => 'ORD-ORDINARY-1',
            'amount' => 50_000,
            'status' => 'pending',
            'provider' => 'gsubz',
            'meta' => ['type' => 'data'],
        ]);

        $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id)->assertNotFound();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => 'Should never reach the queue.',
        ])->assertNotFound();
    }

    private function manualServices(): \App\Services\ManualFulfilmentService
    {
        return app(\App\Services\ManualFulfilmentService::class);
    }

    private function submitManualRequest(User $user, string $slug, array $payload): Order
    {
        $this->actingAs($user)->post('/vtu/manual/'.$slug, $payload)->assertRedirect();

        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
