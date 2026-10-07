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

    public function test_the_nin_page_prints_every_slip_from_the_record_it_stores(): void
    {
        // ConfirmIdent has no print endpoint, but the slip is drawn here from the
        // record a verification leaves behind, so all three buttons are offered
        // with no provider configuration at all and nothing goes to the queue.
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($user)->get('/vtu/nin')->assertOk()->getContent();
        $this->assertStringContainsString('Verify NIN Record', $page);
        foreach (['standard_slip', 'premium_slip', 'long_slip'] as $slip) {
            $this->assertStringContainsString('data-slip-type="'.$slip.'"', $page);
        }
        $this->assertStringNotContainsString('/vtu/manual/nin_slip_print', $page);
        $this->assertStringNotContainsString('NIN Slip Reports', $page);

        // Only the provider's own download report list still needs an endpoint.
        Setting::create(['key' => 'nin_reports_endpoint', 'value' => '/nin_reports']);
        settings_flush_cache();

        $withReports = $this->actingAs($user)->get('/vtu/nin')->assertOk()->getContent();
        $this->assertStringContainsString('NIN Slip Reports', $withReports);
        $this->assertStringContainsString('data-slip-type="standard_slip"', $withReports);
    }

    public function test_a_provider_side_outage_is_blamed_on_the_provider_and_costs_nobody(): void
    {
        // ConfirmIdent answers 400 "Service not available" when the service behind
        // their own endpoint is down. That is not our site failing, and the
        // customer must not pay for it or be left without a way forward.
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(100_000);

        // A configured key is what makes the request reach the provider at all.
        Setting::create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        settings_flush_cache();

        Http::fake([
            'confirmident.com.ng/api/nin_demo' => Http::response(
                ['success' => false, 'message' => 'Service not available'],
                400,
            ),
        ]);

        $response = $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_demo',
            'firstname' => 'Test',
            'lastname' => 'Candidate',
            'dob' => '01-01-1990',
            'gender' => 'male',
        ]);

        $response->assertStatus(422)->assertJson([
            'ok' => false,
            'message' => 'Our NIN provider is reporting a network problem with this check, so nothing was charged. '
                .'Try again in a few minutes, or verify with your 11-digit NIN or the phone number on your NIN '
                .'instead of name and date of birth.',
        ]);

        $this->assertSame(100_000, $this->balance($user));
        $this->assertSame(0, Order::query()->count());

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }

    public function test_a_nin_verification_is_paid_for_and_queued_when_the_owner_switches_it_to_manual(): void
    {
        // Verification answers straight from the provider by default; the queue
        // is where a paid request goes only while the owner has it switched off.
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(100_000);

        Setting::create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        $this->runVerificationManually('nin');
        Http::fake();

        $response = $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '12345678901',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'queued' => true]);
        Http::assertNothingSent();

        $order = $this->latestOrder($user);
        $meta = $order->meta;
        $this->assertSame('pending', $order->status);
        $this->assertSame('manual', $order->provider);
        $this->assertTrue($meta['manual_queue']);
        $this->assertSame('nin_verify', $meta['manual_service']);
        $this->assertSame('by_nin', $meta['submitted']['verification_type']);
        $this->assertSame('12345678901', $meta['submitted']['nin']);
        $this->assertNotEmpty($meta['expected_by']);

        // The customer pays the same ₦250 the automatic page quotes.
        $this->assertSame(75_000, $this->balance($user));
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
        ]);
    }

    public function test_a_bvn_verification_is_paid_for_and_queued_when_the_owner_switches_it_to_manual(): void
    {
        $user = $this->memberWithBalance(100_000);

        Setting::create(['key' => 'bvn_api_key', 'value' => 'test-bvn-key']);
        $this->runVerificationManually('bvn');
        Http::fake();

        $response = $this->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => '12345678901']);

        $response->assertOk()->assertJson(['ok' => true, 'queued' => true]);
        Http::assertNothingSent();

        $order = $this->latestOrder($user);
        $this->assertSame('bvn_verify', $order->meta['manual_service']);
        $this->assertSame('12345678901', $order->meta['submitted']['bvn']);
        $this->assertSame(80_000, $this->balance($user));
    }

    public function test_verifications_run_on_the_provider_without_any_setting_being_saved(): void
    {
        $user = $this->memberWithBalance(100_000);

        $this->assertSame('automatic', identity_verify_mode('nin'));
        $this->assertSame('automatic', identity_verify_mode('bvn'));

        Setting::create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        Setting::create(['key' => 'bvn_api_key', 'value' => 'test-bvn-key']);
        settings_flush_cache();

        Http::fake([
            'confirmident.com.ng/api/nin_search' => Http::response([
                'success' => true,
                'message' => 'Record found',
                'data' => ['nin' => '12345678901', 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
            'confirmident.com.ng/api/bvn_search' => Http::response([
                'success' => true,
                'message' => 'Verification Successfull',
                'data' => ['bvn' => '12345678901', 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
        ]);

        $nin = $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '12345678901',
        ]);
        $nin->assertOk()->assertJson(['ok' => true]);
        $this->assertNull($nin->json('queued'));

        $bvn = $this->actingAs($user)->postJson('/vtu/bvn/verify', ['bvn' => '12345678901']);
        $bvn->assertOk()->assertJson(['ok' => true]);
        $this->assertNull($bvn->json('queued'));

        Http::assertSent(fn ($request) => $request->url() === 'https://confirmident.com.ng/api/nin_search');
        Http::assertSent(fn ($request) => $request->url() === 'https://confirmident.com.ng/api/bvn_search');
        $this->assertSame(
            ['nin_api', 'bvn_api'],
            Order::query()->where('user_id', $user->id)->orderBy('id')->pluck('provider')->all(),
        );
    }

    public function test_the_admin_switch_moves_a_verification_between_the_provider_and_the_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('name="nin_verify_mode"', $page);
        $this->assertStringContainsString('name="bvn_verify_mode"', $page);
        // Nothing saved yet, so the form must show the provider as the picked mode.
        $this->assertSame('automatic', $this->modePickedOnSettingsPage($page, 'nin_verify_mode'));

        Setting::create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        settings_flush_cache();

        Http::fake([
            'confirmident.com.ng/api/nin_search' => Http::response([
                'success' => true,
                'message' => 'Record found',
                'data' => ['nin' => '12345678901', 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
        ]);

        $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '12345678901',
        ])->assertOk()->assertJson(['ok' => true]);

        // Saving manual is all it takes - the next request is worked by a person.
        $this->actingAs($admin)->post('/admin/settings', [
            'nin_verify_mode' => 'manual',
            'bvn_verify_mode' => 'manual',
        ])->assertRedirect();

        $this->assertSame('manual', identity_verify_mode('nin'));
        $this->assertSame('manual', identity_verify_mode('bvn'));
        $saved = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertSame('manual', $this->modePickedOnSettingsPage($saved, 'nin_verify_mode'));
        $this->assertSame('manual', $this->modePickedOnSettingsPage($saved, 'bvn_verify_mode'));

        $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '09876543210',
        ])->assertOk()->assertJson(['ok' => true, 'queued' => true]);

        // And back again the moment the provider is trusted once more.
        $this->actingAs($admin)->post('/admin/settings', ['nin_verify_mode' => 'automatic'])->assertRedirect();
        $this->assertSame('automatic', identity_verify_mode('nin'));

        $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '13579246801',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(['nin_api', 'manual', 'nin_api'], $this->orderProviders($user));
        // Only the two automatic runs reached the provider; the queued one did not.
        Http::assertSentCount(2);
    }

    /**
     * Which option the switch for one service is showing as chosen, so a test
     * can prove the form agrees with the mode the site is actually running in.
     */
    private function modePickedOnSettingsPage(string $page, string $field): string
    {
        preg_match('/name="'.$field.'".*?<\/select>/s', $page, $select);

        preg_match_all('/<option value="([^"]+)"([^>]*)>/s', $select[0] ?? '', $options, PREG_SET_ORDER);
        foreach ($options as $option) {
            if (preg_match('/\bselected\b/', $option[2])) {
                return $option[1];
            }
        }

        return '';
    }

    private function orderProviders(User $user): array
    {
        return Order::query()->where('user_id', $user->id)->orderBy('id')->pluck('provider')->all();
    }

    private function runVerificationManually(string $service): void
    {
        Setting::query()->updateOrCreate(['key' => $service.'_verify_mode'], ['value' => 'manual']);
        settings_flush_cache();
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
