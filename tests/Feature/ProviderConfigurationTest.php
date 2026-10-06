<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_airtime_is_refused_without_contacting_the_provider_when_no_key_is_configured(): void
    {
        $user = $this->memberWithBalance(100_000);

        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/airtime/buy', [
                'service_id' => 'mtnt',
                'phone' => '08012345678',
                'amount' => 500,
            ]);

        $response
            ->assertStatus(400)
            ->assertJson([
                'ok' => false,
                'message' => 'Airtime and data purchases are temporarily unavailable: no provider API key is configured.',
                'balance_kobo' => 100_000,
            ]);

        Http::assertNothingSent();
        $this->assertSame(100_000, $this->balance($user));
        $this->assertSame('failed', $this->latestOrder($user)->status);
    }

    public function test_bvn_verification_reports_the_missing_key_without_charging_the_wallet(): void
    {
        $user = $this->memberWithBalance(100_000);

        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => '12345678901']);

        $response
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'BVN API key is not configured.']);

        Http::assertNothingSent();
        $this->assertSame(100_000, $this->balance($user));
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_nin_validation_is_charged_and_queued_for_manual_fulfilment_without_a_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(100_000);

        $response = $this
            ->actingAs($user)
            ->post('/vtu/nin-validation', [
                'validation_type' => 'no_record',
                'nin' => '12345678901',
            ]);

        $order = $this->latestOrder($user);
        $response->assertRedirect(route('vtu.receipt', $order->id));
        $response->assertSessionHas('success');

        Http::assertNothingSent();

        // The customer is charged at submission, so the wallet must show the debit.
        $this->assertSame(0, $this->balance($user));

        $meta = $order->meta;
        $this->assertSame('pending', $order->status);
        $this->assertSame('manual', $order->provider);
        $this->assertTrue($meta['manual_queue']);
        $this->assertSame('nin_validation', $meta['manual_service']);
        $this->assertSame('no_record', $meta['validation_type']);
        $this->assertSame('12345678901', $meta['submitted']['nin']);
        $this->assertNotEmpty($meta['expected_by']);

        // A human is the only thing that can finish this, so an admin is alerted.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }

    public function test_bvn_retrieval_is_charged_and_queued_for_manual_fulfilment_without_an_endpoint(): void
    {
        $user = $this->memberWithBalance(400_000);

        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/bvn/retrieve', [
                'retrieve_type' => 'phone',
                'phone' => '08012345678',
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'queued' => true]);

        Http::assertNothingSent();

        $order = $this->latestOrder($user);
        $meta = $order->meta;
        $this->assertSame('pending', $order->status);
        $this->assertSame('manual', $order->provider);
        $this->assertTrue($meta['manual_queue']);
        $this->assertSame('bvn_retrieve', $meta['manual_service']);
        $this->assertSame('08012345678', $meta['submitted']['phone']);
        // JH Tech charges ₦2,500 for a phone retrieval, so the site asks ₦3,500
        // and ₦4,000 of wallet leaves ₦500 behind.
        $this->assertSame(50_000, $this->balance($user));
    }

    public function test_purchases_are_sent_to_the_provider_once_a_key_is_configured(): void
    {
        $user = $this->memberWithBalance(100_000);
        Setting::create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        settings_flush_cache();

        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'PROV-1',
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/airtime/buy', [
                'service_id' => 'mtnt',
                'phone' => '08012345678',
                'amount' => 500,
            ]);

        $response->assertOk()->assertJson(['ok' => true]);
        Http::assertSent(fn ($request) => ($request->data()['api'] ?? null) === 'test-provider-key');
        $this->assertSame(51_000, $this->balance($user));
        $this->assertSame('success', $this->latestOrder($user)->status);
    }

    public function test_admin_settings_names_every_integration_that_is_missing_a_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $page = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('These services cannot run yet', $page);
        $this->assertStringContainsString('GSUBZ key', $page);
        $this->assertStringContainsString('NIN key', $page);
        $this->assertStringContainsString('BVN key', $page);

        foreach ([
            'provider_gsubz_api_key' => 'live-provider-key',
            'nin_api_key' => 'live-nin-key',
            'bvn_api_key' => 'live-bvn-key',
        ] as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value]);
        }
        settings_flush_cache();

        $afterKeys = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('GSUBZ key', $afterKeys);
        $this->assertStringNotContainsString('NIN key', $afterKeys);
        $this->assertStringNotContainsString('BVN key', $afterKeys);
        $this->assertStringContainsString('Flutterwave secret key', $afterKeys);
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
