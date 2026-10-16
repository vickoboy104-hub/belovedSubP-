<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A wallet may only be spent for something a provider will actually deliver.
 *
 * Every VTU route used to end the same way: try the provider, and if the provider
 * setting named nothing this site knows how to call, fall through and mark the purchase
 * successful anyway. The customer's money left, the order read "success", and no airtime,
 * cable token or exam pin was ever sent. Worse, that fall-through paid a referral
 * commission, so a mis-typed settings box printed money.
 *
 * The line these tests hold is therefore before the wallet, not after it: a purchase
 * whose provider cannot exist is refused with the balance untouched, no order written,
 * and the purchase identity still unspent so the same form works once an admin puts the
 * right name in the box. Mock is kept where it was always meant to live - a development
 * or test machine, on dummy wallets, paying nobody.
 */
class MockProviderChargeTest extends TestCase
{
    use RefreshDatabase;

    private const PAY = 'https://api.gsubz.com/api/pay/';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::preventStrayRequests();
    }

    /**
     * Every route that takes money for a VTU service, with a body good enough to reach
     * the provider decision.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function purchases(): array
    {
        return [
            'airtime' => ['/vtu/airtime/buy', ['service_id' => 'mtnt', 'phone' => '08012345678', 'amount' => 500]],
            'data' => ['/vtu/data/buy', ['service_id' => 'mtn', 'plan' => 'mtn-500mb', 'phone' => '08012345678', 'amount' => 500]],
            'recharge card' => ['/vtu/recharge-card/buy', ['network' => 'mtn', 'value' => 500, 'num_voucher' => 1]],
            'cable' => ['/vtu/cable/buy', ['service_id' => 'dstv', 'plan' => 'padipe', 'customer_ref' => '12345678', 'base_amount' => 3400]],
            'electricity' => ['/vtu/electricity/buy', ['service_id' => 'ekedc', 'meter_type' => 'prepaid', 'customer_ref' => '12345678', 'amount' => 5000]],
            'exam pin' => ['/vtu/exam/buy', ['pin_code' => 'neco', 'phone' => '08012345678']],
            'premium app' => ['/vtu/premium-apps/buy', ['service_id' => 'netflix', 'plan' => 'premium', 'email' => 'buyer@belovedsubp.com', 'amount' => 3000]],
        ];
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function movements(User $user): int
    {
        return WalletTransaction::query()
            ->where('wallet_id', Wallet::query()->where('user_id', $user->id)->value('id'))
            ->count();
    }

    private function providerSetTo(string $provider): void
    {
        Setting::query()->updateOrCreate(['key' => 'provider'], ['value' => $provider]);
        settings_flush_cache();
    }

    private function liveMachine(): void
    {
        config(['app.env' => 'production']);
    }

    /** @param array<string, mixed> $body */
    private function buy(User $user, string $url, array $body, ?string $intent = null)
    {
        return $this->actingAs($user)->postJson($url, array_merge(
            [PurchaseIntent::FIELD => $intent ?? PurchaseIntent::mint()],
            $body
        ));
    }

    public function test_mock_never_charges_a_wallet_on_a_live_site(): void
    {
        $this->liveMachine();
        $this->providerSetTo('mock');

        foreach ($this->purchases() as $name => [$url, $body]) {
            $user = $this->memberWithBalance(100_000);

            $this->buy($user, $url, $body)
                ->assertStatus(400)
                ->assertJson(['ok' => false, 'balance_kobo' => 100_000]);

            $this->assertSame(100_000, $this->balance($user), "{$name}: mock mode took the wallet's money.");
            $this->assertSame(0, $this->movements($user), "{$name}: mock mode wrote a ledger row.");
            $this->assertSame(
                0,
                Order::query()->where('user_id', $user->id)->count(),
                "{$name}: mock mode wrote an order nobody delivered."
            );
        }

        Http::assertNothingSent();
    }

    public function test_a_provider_name_this_site_cannot_call_is_refused_even_offline(): void
    {
        // Not Mock: a retired name, or a typo in the settings box. The old code read
        // anything unexpected as permission to invent a success.
        $this->providerSetTo('gsubz2');

        foreach ($this->purchases() as $name => [$url, $body]) {
            $user = $this->memberWithBalance(100_000);

            $this->buy($user, $url, $body)->assertStatus(400)->assertJson(['ok' => false]);
            $this->assertSame(100_000, $this->balance($user), "{$name}: an unknown provider was treated as Mock.");
        }

        $this->providerSetTo('');
        $user = $this->memberWithBalance(100_000);
        $this->buy($user, '/vtu/airtime/buy', $this->purchases()['airtime'][1])
            ->assertStatus(400);
        $this->assertSame(100_000, $this->balance($user), 'An empty provider box was treated as Mock.');
    }

    public function test_a_refused_purchase_leaves_the_form_free_to_buy_once_the_box_is_fixed(): void
    {
        // The key is saved from the start: the refusal must come from the provider box
        // reading Mock, not from a missing credential.
        Setting::query()->create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        $this->liveMachine();
        $this->providerSetTo('mock');

        $user = $this->memberWithBalance(100_000);
        $intent = PurchaseIntent::mint();
        $body = $this->purchases()['airtime'][1];

        $this->buy($user, '/vtu/airtime/buy', $body, $intent)->assertStatus(400);

        // An admin puts a real provider back; the same page, never reloaded, now works.
        $this->providerSetTo('gsubz');
        Http::fake([
            self::PAY => Http::response(['status' => '1', 'description' => 'Transaction successful', 'transactionID' => 'FIX-1']),
        ]);

        $this->buy($user, '/vtu/airtime/buy', $body, $intent)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(51_000, $this->balance($user));
        $this->assertSame('success', $this->latestOrder($user)->status);
    }

    public function test_mock_still_rehearses_purchases_on_a_development_machine(): void
    {
        config(['app.env' => 'local']);
        $this->providerSetTo('mock');

        $user = $this->memberWithBalance(100_000);
        $this->buy($user, '/vtu/airtime/buy', $this->purchases()['airtime'][1])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Airtime purchase successful (Mock Mode)!']);

        $order = $this->latestOrder($user);
        $this->assertSame('success', $order->status);
        $this->assertTrue($order->meta['simulated'] ?? false, 'A rehearsal order is not marked as one.');
        $this->assertLessThan(100_000, $this->balance($user));
        Http::assertNothingSent();
    }

    public function test_a_simulated_purchase_pays_no_referral_commission(): void
    {
        config(['app.env' => 'local']);
        $this->providerSetTo('mock');

        foreach ([
            'referral_system_enabled' => '1',
            'referral_enabled_airtime' => '1',
            'referral_percent_airtime' => '5',
        ] as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value]);
        }
        settings_flush_cache();

        $referrer = User::factory()->create(['email_verified_at' => now(), 'referral_code' => 'MOCKREF01']);
        $buyer = $this->memberWithBalance(100_000);
        $buyer->forceFill(['referred_by_user_id' => $referrer->id])->save();

        // The buyer has funded their wallet, which is the gate commission normally waits for.
        Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->transactions()->create([
            'type' => 'credit',
            'amount' => 100_000,
            'reference' => 'MOCK-FUND-1',
            'status' => 'success',
            'channel' => 'test',
            'description' => 'Test funding',
        ]);

        $this->buy($buyer, '/vtu/airtime/buy', $this->purchases()['airtime'][1])->assertOk();

        $order = $this->latestOrder($buyer);
        $this->assertArrayNotHasKey('referral_commission_status', (array) $order->meta);
        $this->assertSame(
            0,
            (int) $referrer->fresh()->referral_earnings_balance,
            'A purchase nobody delivered paid a real commission.'
        );
    }

    public function test_a_configured_provider_still_sells_after_the_guard(): void
    {
        $this->providerSetTo('gsubz');
        Setting::query()->create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        settings_flush_cache();

        Http::fake([
            self::PAY => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'REAL-1',
                'content' => ['pin' => '9AB34CD71EF', 'serial_number' => '123456789012345'],
            ]),
        ]);

        $purchases = $this->purchases();

        // Data and cable price themselves off the provider's synced plan list, which a
        // fake of this shape cannot offer; both are covered with real plans by
        // ProviderConfigurationTest and PurchaseIntentSafetyTest.
        foreach (array_diff(array_keys($purchases), ['data', 'cable']) as $name) {
            [$url, $body] = $purchases[$name];
            $user = $this->memberWithBalance(1_000_000);

            $this->buy($user, $url, $body)->assertOk()->assertJson(['ok' => true]);
            $this->assertSame('success', $this->latestOrder($user)->status, "{$name}: a real provider was refused.");
            $this->assertLessThan(1_000_000, $this->balance($user), "{$name}: a real sale was free.");
        }
    }

    public function test_the_settings_page_says_why_mock_blocks_every_sale(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->providerSetTo('mock');

        $page = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('Customers cannot buy airtime', $page);
        $this->assertStringContainsString('Mock (Test Mode)', $page);
        $this->assertStringNotContainsString('to rehearse orders without a provider', $page);

        $this->providerSetTo('gsubz');
        $afterFix = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('Customers cannot buy airtime', $afterFix);
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
