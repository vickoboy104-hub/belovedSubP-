<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A request that did not work should end at the service that can put it right,
 * with the number the customer already gave carried over rather than asked twice.
 */
class ManualRequestHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_failed_validation_offers_the_correction_it_points_at(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->validationOrder($user, '12345678901', 'failed');

        $page = $this->actingAs($user)->get('/vtu/nin-validation')->assertOk()->getContent();

        $this->assertStringContainsString('/vtu/manual/nin_modification?nin=12345678901', $page);
        $this->assertStringContainsString('Correct this record', $page);
    }

    public function test_a_validation_that_worked_is_not_offered_a_correction(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->validationOrder($user, '22334455667', 'success');

        $page = $this->actingAs($user)->get('/vtu/nin-validation')->assertOk()->getContent();

        $this->assertStringNotContainsString('Correct this record', $page);
    }

    public function test_the_service_a_customer_lands_on_opens_with_their_number_filled_in(): void
    {
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($user)
            ->get('/vtu/manual/nin_modification?nin=12345678901')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="nin"[^>]*value="12345678901"|value="12345678901"[^>]*name="nin"/',
            $page,
        );
    }

    public function test_a_query_parameter_that_is_not_one_of_the_services_own_fields_is_ignored(): void
    {
        $user = $this->memberWithBalance(100_000);

        $page = $this->actingAs($user)
            ->get('/vtu/manual/nin_modification?wallet_balance=999999&status=success')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="wallet_balance"', $page);
        $this->assertStringNotContainsString('name="status"', $page);
        $this->assertStringNotContainsString('999999', $page);
    }

    private function validationOrder(User $user, string $nin, string $status): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'service_id' => Service::create(['slug' => 'nin_validation', 'name' => 'NIN VALIDATION'])->id,
            'customer_ref' => $nin,
            'amount' => 2_500,
            'provider' => 'manual',
            'status' => $status,
            'meta' => [
                'type' => 'nin_validation',
                'validation_type' => 'no_record',
                'message' => $status === 'failed' ? 'No record found for the supplied NIN' : 'Validation Successful',
            ],
        ]);
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
