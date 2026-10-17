<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BvnServicesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_the_bvn_page_locks_the_price_until_a_retrieval_category_is_chosen(): void
    {
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($user)->get('/vtu/bvn')->assertOk()->getContent();

        // The figure lives in a disabled field that starts hidden, so nobody reads a
        // retrieval price before saying which kind of retrieval they want.
        $this->assertStringContainsString('<div class="hidden" data-price-lock>', $page);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="retrievePrice"[^>]*\bdisabled\b/',
            $page,
        );
    }

    public function test_the_bvn_page_lists_every_earlier_request_with_the_provider_answer(): void
    {
        $user = $this->memberWithBalance(100_000);

        $order = Order::create([
            'user_id' => $user->id,
            'service_id' => $this->serviceId('bvn_verify'),
            'customer_ref' => '12345678901',
            'amount' => 1_500,
            'provider' => 'bvn_api',
            'status' => 'success',
            'meta' => [
                'type' => 'bvn',
                'service_type' => 'verify',
                'message' => 'Verification Successful',
            ],
        ]);

        $page = $this->actingAs($user)->get('/vtu/bvn')->assertOk()->getContent();

        $this->assertStringContainsString('BVN Requests', $page);
        $this->assertStringContainsString('12345678901', $page);
        $this->assertStringContainsString('Verification Successful', $page);
        $this->assertStringContainsString(route('vtu.receipt', $order->id), $page);
    }

    public function test_another_members_bvn_requests_stay_off_this_page(): void
    {
        $viewer = $this->memberWithBalance(100_000);
        $other = $this->memberWithBalance(100_000);

        Order::create([
            'user_id' => $other->id,
            'service_id' => $this->serviceId('bvn_verify'),
            'customer_ref' => '98765432109',
            'amount' => 1_500,
            'provider' => 'bvn_api',
            'status' => 'success',
            'meta' => ['type' => 'bvn', 'service_type' => 'verify'],
        ]);

        $page = $this->actingAs($viewer)->get('/vtu/bvn')->assertOk()->getContent();

        $this->assertStringNotContainsString('98765432109', $page);
        $this->assertStringContainsString('No BVN requests yet.', $page);
    }

    private function serviceId(string $slug): int
    {
        return Service::create(['slug' => $slug, 'name' => strtoupper($slug)])->id;
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
