<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a BVN check hands back, and what a BVN slip costs to make.
 *
 * The record is shown on a page of its own that closes once it has been seen, and
 * the slip is drawn here rather than bought from the provider, because ConfirmIdent
 * publishes no print endpoint at all.
 */
class BvnDetailsAndSlipTest extends TestCase
{
    use RefreshDatabase;

    private const BVN = '12345678901';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::create(['key' => 'bvn_api_key', 'value' => 'test-bvn-key']);
        settings_flush_cache();

        Http::preventStrayRequests();
    }

    public function test_a_successful_check_shows_the_record_once_and_closes_when_it_is_seen(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->fakeProvider();

        $verified = $this->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('amount_naira', 200);

        $detailsUrl = $verified->json('details_url');
        $this->assertIsString($detailsUrl);
        $this->assertStringContainsString('/vtu/bvn/details/', $detailsUrl);
        $this->assertSame(80_000, $this->balance($user));

        $page = $this->actingAs($user)->get($detailsUrl)->assertOk()->getContent();
        $this->assertStringContainsString('TESTFIRST', $page);
        $this->assertStringContainsString('Residential Address', $page);
        $this->assertStringContainsString('Watchlisted', $page);
        // The provider answers some rows of this grid and not others, and an empty
        // row is shown as a dash rather than dropped, so the gap is visible.
        $this->assertStringContainsString('—', $page);
        $this->assertStringContainsString("I've Seen the Details. Go Back", $page);
        $this->assertStringContainsString('data:image/jpeg;base64,', $page);

        $orderId = (int) $verified->json('order_id');
        $this->actingAs($user)->post('/vtu/bvn/details/'.$orderId.'/seen')
            ->assertRedirect();

        $this->actingAs($user)->get($detailsUrl)->assertNotFound();
    }

    public function test_the_details_page_is_not_a_link_another_member_can_open(): void
    {
        $owner = $this->memberWithBalance(100_000);
        $stranger = $this->memberWithBalance(100_000);
        $this->fakeProvider();

        $orderId = (int) $this->actingAs($owner)
            ->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])
            ->json('order_id');

        // Even with the address in hand, a member who never ran the check gets
        // nothing, because the grant lives in the session that paid for it.
        $this->actingAs($stranger)->get('/vtu/bvn/details/'.$orderId)->assertNotFound();
    }

    public function test_a_failed_check_never_debits_the_wallet_or_opens_a_details_page(): void
    {
        $user = $this->memberWithBalance(100_000);

        Http::fake([
            'confirmident.com.ng/api/bvn_search' => Http::response(
                ['success' => false, 'message' => 'Invalid BVN number'],
                400,
            ),
        ]);

        $this->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])
            ->assertStatus(422)
            ->assertJsonMissingPath('details_url');

        $this->assertSame(100_000, $this->balance($user));
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_printing_a_slip_reuses_the_record_and_never_asks_the_provider_twice(): void
    {
        $user = $this->memberWithBalance(500_000);
        $this->fakeProvider();

        $this->actingAs($user)->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])->assertOk();

        $slip = $this->actingAs($user)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('amount_naira', 150);

        // 200 for the check, 150 for the slip, and one provider call for both.
        $this->assertSame(465_000, $this->balance($user));
        Http::assertSentCount(1);

        $orderId = (int) $slip->json('order_id');
        $printOrder = Order::query()->findOrFail($orderId);
        $this->assertSame(0.0, (float) $printOrder->meta['provider_cost_naira']);
        $this->assertSame('print', $printOrder->meta['service_type']);

        $this->actingAs($user)->get($slip->json('slip_url'))
            ->assertOk()
            ->assertSee('Your Slip is Ready', false);

        $sheet = $this->actingAs($user)
            ->get($slip->json('slip_url').'/sheet')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Verification successful', $sheet);
        $this->assertStringContainsString(self::BVN, $sheet);
        $this->assertStringContainsString('TESTLAST', $sheet);
        // The provider's own address and the raw answer it gave must never reach a
        // document the customer hands to a third party.
        $this->assertStringNotContainsString('confirmident', strtolower($sheet));
        $this->assertStringNotContainsString('172.18', $sheet);
    }

    public function test_a_slip_can_be_opened_again_without_a_second_charge(): void
    {
        $user = $this->memberWithBalance(500_000);
        $this->fakeProvider();

        $this->actingAs($user)->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])->assertOk();
        $slipUrl = $this->actingAs($user)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->json('slip_url');

        $before = $this->balance($user);

        $this->actingAs($user)->get($slipUrl)->assertOk();
        $this->actingAs($user)->get($slipUrl.'/sheet')->assertOk();
        $this->actingAs($user)->get($slipUrl.'/sheet')->assertOk();

        $this->assertSame($before, $this->balance($user));
        $this->assertSame(1, Order::query()->where('meta->service_type', 'print')->count());
    }

    public function test_a_slip_for_a_number_never_checked_costs_the_provider_call_it_needed(): void
    {
        $user = $this->memberWithBalance(500_000);
        $this->fakeProvider();

        $this->actingAs($user)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(80.0, (float) Order::query()
            ->where('meta->service_type', 'print')
            ->first()->meta['provider_cost_naira']);
    }

    public function test_a_slip_request_the_provider_refuses_charges_nothing(): void
    {
        $user = $this->memberWithBalance(500_000);

        Http::fake([
            'confirmident.com.ng/api/bvn_search' => Http::response(
                ['success' => false, 'message' => 'Provider wallet depleted'],
                400,
            ),
        ]);

        $this->actingAs($user)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->assertStatus(422)
            ->assertJson(['message' => 'Provider wallet depleted']);

        $this->assertSame(500_000, $this->balance($user));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_hand_run_switch_sends_a_slip_to_the_queue_without_calling_the_provider(): void
    {
        $user = $this->memberWithBalance(500_000);
        Setting::create(['key' => 'bvn_verify_mode', 'value' => 'manual']);
        settings_flush_cache();

        Http::fake();

        $queued = $this->actingAs($user)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->assertOk()
            ->assertJsonPath('queued', true);

        Http::assertNothingSent();

        $order = Order::query()->findOrFail((int) $queued->json('order_id'));
        $this->assertSame('manual', $order->provider);
        $this->assertSame('pending', $order->status);
        $this->assertSame('print', $order->meta['service_type']);
        // Priced as the hand-run job it is, not at the instant rate.
        $this->assertSame(100_000, $order->amount);
    }

    public function test_a_slip_belonging_to_someone_else_is_not_there(): void
    {
        $owner = $this->memberWithBalance(500_000);
        $stranger = $this->memberWithBalance(500_000);
        $this->fakeProvider();

        $this->actingAs($owner)->postJson('/vtu/bvn/verify', ['bvn' => self::BVN])->assertOk();
        $slipUrl = $this->actingAs($owner)
            ->postJson('/vtu/bvn/print', ['bvn' => self::BVN])
            ->json('slip_url');

        $this->actingAs($stranger)->get($slipUrl)->assertNotFound();
        $this->actingAs($stranger)->get($slipUrl.'/sheet')->assertNotFound();
    }

    private function fakeProvider(): void
    {
        Http::fake([
            'confirmident.com.ng/api/bvn_search' => Http::response([
                'success' => true,
                'message' => 'Verification Successful',
                'data' => [
                    'firs_tname' => 'TESTFIRST',
                    'middlename' => 'TESTMIDDLE',
                    'last_name' => 'TESTLAST',
                    'gender' => 'Male',
                    'date_of_birth' => '01-01-1990',
                    'phone_number' => '08012345678',
                    'alt_number' => '',
                    'email' => 'test@example.com',
                    'State' => 'Test State',
                    'bvn' => self::BVN,
                    'nin' => '99887766554',
                    'image' => '/9j/'.base64_encode('not-a-real-jpeg'),
                ],
            ]),
        ]);
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
