<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The price of an identity job is an answer to a question the customer has not
 * always asked yet, so it waits until the question is answered.
 */
class IdentityPriceLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_the_validation_page_holds_its_price_back_until_a_category_is_named(): void
    {
        $user = $this->memberWithBalance(100_000);

        Setting::create(['key' => 'price_nin_validation_no_record', 'value' => '2500']);
        settings_flush_cache();

        $page = $this->actingAs($user)->get('/vtu/nin-validation')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="validationPrice"[^>]*\bdisabled\b/',
            $page,
        );
        // Nothing on the page quotes a figure the customer has not earned yet.
        $this->assertStringNotContainsString('This request costs', $page);
        $this->assertStringNotContainsString('₦2,500.00', $page);
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
