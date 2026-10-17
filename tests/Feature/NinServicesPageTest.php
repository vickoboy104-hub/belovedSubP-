<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The NIN page answers two questions with money: what a search costs, and what a
 * slip costs. Both figures have to be the number the server will actually take,
 * and both have to wait until the customer has said which one they mean. The
 * page also carries the customer's own earlier requests, because a verification
 * they already paid for should not look like something they must buy again.
 */
class NinServicesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_both_fees_live_in_locked_fields_that_the_server_never_prefills(): void
    {
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($user)->get('/vtu/nin')->assertOk()->getContent();

        foreach (['verifyPrice', 'printPrice'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*id="'.$id.'"[^>]*\bdisabled\b/',
                $page,
            );
        }

        // The field is filled by the script once a choice exists, so the markup
        // itself must carry no figure a customer could read as their own price.
        $this->assertMatchesRegularExpression('/<input[^>]*id="verifyPrice"[^>]*value=""/', $page);
        // A chip that stated one fee for all three search types is what this
        // replaced; it must not come back.
        $this->assertStringNotContainsString('verifyPriceBadge', $page);
    }

    public function test_a_slip_card_quotes_the_amount_the_server_will_take(): void
    {
        $user = $this->memberWithBalance(100_000);

        Setting::query()->create(['key' => 'price_nin_slip_standard', 'value' => '1500']);
        Setting::query()->create(['key' => 'markup_nin_print', 'value' => '200']);
        settings_flush_cache();

        $page = $this->actingAs($user)->get('/vtu/nin')->assertOk()->getContent();

        // Printing costs the slip rate plus the owner's print markup, so a card
        // showing the rate alone would promise a cheaper job than the one charged.
        $this->assertStringContainsString('data-slip-type="standard_slip"', $page);
        $this->assertStringContainsString('&#8358;1,700.00', $page);
        $this->assertStringNotContainsString('&#8358;1,500.00', $page);
    }

    public function test_the_nin_page_lists_every_earlier_request_with_the_answer_it_got(): void
    {
        $user = $this->memberWithBalance(100_000);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'service_id' => $this->serviceId('nin_slip_print'),
            'customer_ref' => '12345678901',
            'amount' => 1_700,
            'provider' => 'nin_api',
            'status' => 'success',
            'meta' => [
                'type' => 'nin',
                'service_type' => 'print',
                'result_text' => 'Premium slip printed',
            ],
        ]);

        $page = $this->actingAs($user)->get('/vtu/nin')->assertOk()->getContent();

        $this->assertStringContainsString('NIN Requests', $page);
        $this->assertStringContainsString('Slip print', $page);
        $this->assertStringContainsString('Premium slip printed', $page);
        $this->assertStringContainsString(route('vtu.receipt', $order->id), $page);
    }

    public function test_another_members_nin_requests_stay_off_this_page(): void
    {
        $viewer = $this->memberWithBalance(100_000);
        $other = $this->memberWithBalance(100_000);

        Order::query()->create([
            'user_id' => $other->id,
            'service_id' => $this->serviceId('nin_verify'),
            'customer_ref' => '98765432109',
            'amount' => 1_600,
            'provider' => 'nin_api',
            'status' => 'success',
            'meta' => ['type' => 'nin', 'service_type' => 'verify'],
        ]);

        $page = $this->actingAs($viewer)->get('/vtu/nin')->assertOk()->getContent();

        $this->assertStringNotContainsString('98765432109', $page);
        $this->assertStringContainsString('No NIN requests yet.', $page);
    }

    private function serviceId(string $slug): int
    {
        return Service::query()->create(['slug' => $slug, 'name' => strtoupper($slug)])->id;
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
