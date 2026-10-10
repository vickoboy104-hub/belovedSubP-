<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A service that rewrites or reprints an identity record must not open its form
 * until the customer has said they understand what is being authorised.
 */
class ManualConsentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_the_modification_form_stays_behind_its_terms(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $page = $this->actingAs($user)->get('/vtu/manual/nin_modification')->assertOk()->getContent();

        $this->assertStringContainsString('data-consent-gate', $page);
        $this->assertStringContainsString('data-consent-form', $page);
        $this->assertStringContainsString('Consent and authorisation', $page);
        $this->assertStringContainsString('I agree, show me the form', $page);
        $this->assertStringContainsString('banks and phone networks can take considerably longer', $page);

        // A service that asks nothing extra must not put the customer through a gate.
        $open = $this->actingAs($user)->get('/vtu/manual/ipe_clearance')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-consent-gate', $open);
        $this->assertStringNotContainsString('data-consent-form', $open);
    }

    public function test_a_gated_request_is_refused_and_costs_nothing_when_the_terms_are_not_accepted(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                'nin' => '12345678901',
                'field_to_modify' => 'name',
                'new_value' => 'A New Name',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('consent_agreed');

        $this->assertSame(5_000_000, $this->balance($user));
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_agreeing_to_the_terms_is_recorded_on_the_request_itself(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->actingAs($user)
            ->post('/vtu/manual/bvn_print', [
                'bvn' => '12345678901',
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        // The tick is kept with the submission, so a later dispute can be answered
        // with what the customer actually accepted rather than with today's wording.
        $this->assertSame('1', $order->meta['submitted']['consent_agreed']);
        $this->assertLessThan(5_000_000, $this->balance($user));
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
