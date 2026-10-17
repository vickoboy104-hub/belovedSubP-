<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private function putSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
        Cache::forget('settings.all');
    }

    private function makeUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user;
    }

    private function award(Order $order): void
    {
        $controller = app(\App\Http\Controllers\VtuController::class);
        $method = new ReflectionMethod($controller, 'awardReferralCommission');
        $method->setAccessible(true);
        $method->invoke($controller, $order);
    }

    private function completedOrder(User $buyer, int $amountKobo): Order
    {
        $service = Service::query()->create(['slug' => 'airtime-test', 'name' => 'Airtime']);

        return Order::query()->create([
            'user_id' => $buyer->id,
            'service_id' => $service->id,
            'customer_ref' => $buyer->phone,
            'amount' => $amountKobo,
            'status' => 'success',
            'meta' => ['type' => 'airtime'],
        ]);
    }

    public function test_referral_page_requires_authentication(): void
    {
        $this->get('/referral')->assertRedirect('/login');
    }

    public function test_referral_page_issues_a_code_and_shares_a_link(): void
    {
        $user = $this->makeUser();
        $this->be($user);

        $response = $this->get('/referral');
        $response->assertOk();

        $user->refresh();
        $this->assertNotSame('', (string) $user->referral_code);
        $response->assertSee($user->referral_code, false);
        $response->assertSee('/r/' . $user->referral_code, false);
    }

    public function test_referral_visit_stores_a_valid_code_for_registration(): void
    {
        $referrer = $this->makeUser(['referral_code' => 'ABCDE123']);

        $response = $this->get('/r/abcde123');
        $response->assertRedirect();
        $this->assertStringContainsString('/register', $response->headers->get('Location'));
        $response->assertSessionHas('referral_code', 'ABCDE123');
        $this->assertNotNull($referrer->id);
    }

    public function test_unknown_referral_code_is_rejected_and_not_stored(): void
    {
        $response = $this->get('/r/NOPE9999');
        $response->assertRedirect();
        $this->assertStringContainsString('/register', $response->headers->get('Location'));
        $response->assertSessionHas('errors');
        $this->assertNull(session('referral_code'));
    }

    public function test_registration_with_a_referral_code_attributes_the_referrer(): void
    {
        $referrer = $this->makeUser(['referral_code' => 'XYZ12345']);

        $this->post('/register', [
            'first_name' => 'Referred',
            'last_name' => 'Person',
            'phone' => '08033334444',
            'email' => 'referred@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'XYZ12345',
        ])->assertRedirect();

        $buyer = User::query()->where('email', 'referred@example.com')->firstOrFail();
        $this->assertSame($referrer->id, (int) $buyer->referred_by_user_id);
        $this->assertNotSame('', (string) $buyer->referral_code, 'New users get their own code to pass on.');
    }

    public function test_registration_with_an_unknown_code_creates_no_follower_link(): void
    {
        $this->post('/register', [
            'first_name' => 'Nobody',
            'last_name' => 'Referred',
            'phone' => '08035556666',
            'email' => 'orphan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'GHOST000',
        ])->assertRedirect();

        $buyer = User::query()->where('email', 'orphan@example.com')->firstOrFail();
        $this->assertNull($buyer->referred_by_user_id);
    }

    public function test_commission_is_credited_to_the_referrer_after_funded_purchase(): void
    {
        $this->putSettings([
            'referral_system_enabled' => '1',
            'referral_enabled_airtime' => '1',
            'referral_percent_airtime' => '5',
        ]);

        $referrer = $this->makeUser(['referral_code' => 'REF10001']);
        $buyer = $this->makeUser(['referred_by_user_id' => $referrer->id]);

        // The gate: the buyer must have funded their wallet at least once.
        Wallet::query()->where('user_id', $buyer->id)->first()->transactions()->create([
            'type' => 'credit',
            'amount' => 100000,
            'reference' => 'FND-TEST-1',
            'status' => 'success',
            'channel' => 'test',
            'description' => 'Test funding',
        ]);

        $order = $this->completedOrder($buyer, 200000); // N2,000
        $this->award($order);

        $order->refresh();
        $referrer->refresh();

        $this->assertSame('credited', $order->meta['referral_commission_status']);
        $this->assertSame(10000, (int) $order->meta['referral_commission_kobo'], '5% of N2,000 is N100.');
        $this->assertSame(10000, (int) $referrer->referral_earnings_balance);
        $this->assertSame(10000, (int) $referrer->referral_earnings_total);
        $this->assertNotNull($buyer->fresh()->referral_qualified_at);
    }

    public function test_commission_is_only_awarded_once_per_order(): void
    {
        $this->putSettings([
            'referral_system_enabled' => '1',
            'referral_enabled_airtime' => '1',
            'referral_percent_airtime' => '5',
        ]);

        $referrer = $this->makeUser(['referral_code' => 'REF10002']);
        $buyer = $this->makeUser(['referred_by_user_id' => $referrer->id, 'referral_qualified_at' => now()]);
        $order = $this->completedOrder($buyer, 200000);

        $this->award($order);
        $this->award($order->fresh());

        $referrer->refresh();
        $this->assertSame(10000, (int) $referrer->referral_earnings_balance, 'Replaying the order must not double pay.');
    }

    public function test_unfunded_buyer_pays_no_commission_and_stays_pending(): void
    {
        $this->putSettings([
            'referral_system_enabled' => '1',
            'referral_enabled_airtime' => '1',
            'referral_percent_airtime' => '5',
        ]);

        $referrer = $this->makeUser(['referral_code' => 'REF10003']);
        $buyer = $this->makeUser(['referred_by_user_id' => $referrer->id]);
        $order = $this->completedOrder($buyer, 200000);

        $this->award($order);

        $order->refresh();
        $referrer->refresh();

        $this->assertSame('awaiting_first_funding', $order->meta['referral_commission_status']);
        $this->assertSame(0, (int) $referrer->referral_earnings_balance);
        $this->assertNull($buyer->fresh()->referral_qualified_at);
    }

    public function test_disabled_service_type_pays_no_commission(): void
    {
        $this->putSettings([
            'referral_system_enabled' => '1',
            'referral_enabled_airtime' => '0',
            'referral_percent_airtime' => '5',
        ]);

        $referrer = $this->makeUser(['referral_code' => 'REF10004']);
        $buyer = $this->makeUser(['referred_by_user_id' => $referrer->id, 'referral_qualified_at' => now()]);
        $order = $this->completedOrder($buyer, 200000);

        $this->award($order);

        $this->assertSame('service_disabled', $order->fresh()->meta['referral_commission_status']);
        $this->assertSame(0, (int) $referrer->fresh()->referral_earnings_balance);
    }

    public function test_referral_page_shows_the_applied_code_to_a_new_visitor(): void
    {
        $this->makeUser(['referral_code' => 'SHOW22222']);

        $this->followingRedirects()->get('/r/SHOW22222')
            ->assertOk()
            ->assertSee('Referral code applied')
            ->assertSee('SHOW22222');
    }

    public function test_globally_disabled_system_pays_no_commission(): void
    {
        $this->putSettings([
            'referral_system_enabled' => '0',
            'referral_enabled_airtime' => '1',
            'referral_percent_airtime' => '5',
        ]);

        $referrer = $this->makeUser(['referral_code' => 'REF10007']);
        $buyer = $this->makeUser(['referred_by_user_id' => $referrer->id, 'referral_qualified_at' => now()]);
        $order = $this->completedOrder($buyer, 200000);

        $this->award($order);

        $this->assertSame('system_disabled', $order->fresh()->meta['referral_commission_status']);
        $this->assertSame(0, (int) $referrer->fresh()->referral_earnings_balance);
    }

    public function test_withdrawal_moves_earnings_into_the_wallet(): void
    {
        $user = $this->makeUser(['referral_code' => 'REF10005', 'referral_earnings_balance' => 10000]);
        $this->be($user);

        $response = $this->post('/referral/withdraw', ['amount' => 50]);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $user->refresh();
        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(5000, (int) $user->referral_earnings_balance);
        $this->assertSame(5000, (int) $user->referral_earnings_withdrawn);
        $this->assertSame(5000, (int) $wallet->balance);

        $transaction = $wallet->transactions()->where('channel', 'referral_withdrawal')->firstOrFail();
        $this->assertSame(5000, (int) $transaction->amount);
        $this->assertSame('success', $transaction->status);
        $this->assertSame(0, (int) $transaction->meta['balance_before_kobo']);
        $this->assertSame(5000, (int) $transaction->meta['balance_after_kobo']);
    }

    public function test_withdrawal_cannot_exceed_available_earnings(): void
    {
        $user = $this->makeUser(['referral_code' => 'REF10006', 'referral_earnings_balance' => 10000]);
        $this->be($user);

        $response = $this->post('/referral/withdraw', ['amount' => 500]);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $user->refresh();
        $this->assertSame(10000, (int) $user->referral_earnings_balance, 'Rejected withdrawal must not move money.');
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }
}
