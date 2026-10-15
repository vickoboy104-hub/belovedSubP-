<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\GsubzApi;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Asking a provider to spend money is the one thing here that cannot be undone.
 *
 * Every outbound call in this site sits inside a request that may be sent twice by
 * accident - a double-tap, a browser retrying a POST it believes timed out - and each
 * of those is answered by the purchase identity. But a client that retries inside its
 * own call is a third way the same purchase reaches the provider twice, and this time
 * nothing above it can see it happening: the customer's wallet is debited once, one
 * order is written down, and the provider has supplied the airtime twice.
 *
 * So a purchase is sent once and never resent by this site. What settles an unclear
 * answer is the read-only lookup of the reference we already hold, which is free to be
 * tried again because asking "did that go through?" twice bills nobody.
 */
class ChargeableProviderSendTest extends TestCase
{
    use RefreshDatabase;

    private const PAY = 'https://api.gsubz.com/api/pay/';
    private const VERIFY = 'https://api.gsubz.com/api/verify/';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::preventStrayRequests();

        Setting::query()->create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        settings_flush_cache();

        $this->customer = $this->memberWithBalance(100_000);
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(): int
    {
        return (int) Wallet::query()->where('user_id', $this->customer->id)->value('balance');
    }

    private function movements(): int
    {
        return WalletTransaction::query()
            ->where('wallet_id', Wallet::query()->where('user_id', $this->customer->id)->value('id'))
            ->count();
    }

    private function buyAirtime(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->customer)->postJson('/vtu/airtime/buy', [
            PurchaseIntent::FIELD => PurchaseIntent::mint(),
            'service_id' => 'mtnt',
            'phone' => '08012345678',
            'amount' => 500,
        ]);
    }

    /**
     * A provider that answers 500 halfway through a purchase has refused our request,
     * not proved the airtime was never sent. Sending it again from inside the same
     * click would be a second supply we have only debited once.
     */
    public function test_a_purchase_the_provider_refused_with_a_server_error_is_sent_once_only(): void
    {
        $asks = 0;

        Http::fake([
            self::PAY => function () use (&$asks) {
                $asks++;

                return Http::response(['status' => '0', 'description' => 'Provider server error'], 500);
            },
            self::VERIFY => Http::response(['status' => '0', 'description' => 'Transaction not found']),
        ]);

        $this->buyAirtime()->assertStatus(400)->assertJson(['ok' => false]);

        $this->assertSame(1, $asks, 'A purchase the provider refused was sent more than once.');
        $this->assertSame(100_000, $this->balance(), 'The refused purchase did not give the money back.');
        $this->assertSame(2, $this->movements(), 'Debit and refund are one pair, nothing more.');

        $order = Order::query()->where('user_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $order->status);
    }

    public function test_a_purchase_that_never_reached_the_provider_is_sent_once_only(): void
    {
        $asks = 0;

        Http::fake([
            self::PAY => function () use (&$asks) {
                $asks++;

                return Http::failedConnection('cURL error 7: Failed to connect to api.gsubz.com');
            },
            self::VERIFY => Http::response(['status' => '0', 'description' => 'Transaction not found']),
        ]);

        $this->buyAirtime()->assertStatus(400)->assertJson(['ok' => false]);

        // A connect that never completed still leaves this site unable to prove the
        // provider heard nothing, so the one send is all it is ever given.
        $this->assertSame(1, $asks, 'A purchase the provider may never have heard was sent more than once.');
        $this->assertSame(100_000, $this->balance());
    }

    /**
     * The boundary, stated as a test: it is the chargeable send that is limited to one,
     * not every call. A read that asks about a reference we already hold bills nobody
     * however many times it is asked, so it keeps the retries that make our own
     * reconciliation survive a shaky connection.
     */
    public function test_a_read_only_lookup_of_a_reference_we_hold_may_still_be_tried_again(): void
    {
        $asks = 0;

        Http::fake([
            self::VERIFY => function () use (&$asks) {
                $asks++;

                return Http::response(['status' => '0', 'description' => 'Provider server error'], 500);
            },
        ]);

        app(GsubzApi::class)->verify('REQ-READ-ONLY');

        $this->assertGreaterThan(1, $asks, 'The read-only lookup lost the retries that keep reconciliation alive.');
    }

    public function test_a_service_refusal_that_never_left_the_site_still_sends_nothing_at_all(): void
    {
        Http::fake();

        Setting::query()->where('key', 'provider_gsubz_api_key')->delete();
        settings_flush_cache();

        $response = app(GsubzApi::class)->pay(['requestID' => 'REQ-NO-KEY', 'phone' => '08012345678']);

        $this->assertFalse($response['ok']);
        $this->assertTrue($response['unconfigured']);
        Http::assertNothingSent();
    }
}
