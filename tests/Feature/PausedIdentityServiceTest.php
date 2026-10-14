<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The provider's paused service keeps its price and the customer's own history on
 * screen and closes only the request form. A switch on the settings board has to
 * mean exactly that, and it must never be offered for a service whose form lives
 * somewhere it does not reach.
 */
class PausedIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_paused_service_keeps_its_price_and_history_and_loses_only_the_form(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->actingAs($user)->post('/vtu/manual/nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'name',
            'fname' => 'A New',
            'lname' => 'Name',
            'consent_agreed' => '1',
        ])->assertRedirect();

        $this->pause('nin_modification');

        $page = $this->actingAs($user)->get('/vtu/manual/nin_modification')->assertOk()->getContent();

        $this->assertStringContainsString('data-service-paused', $page);
        $this->assertStringContainsString('Service under maintenance', $page);
        $this->assertStringContainsString('We will inform you when it is back', $page);

        // What the customer can still act on: what it costs, and where the last
        // request got to.
        $this->assertStringContainsString('This service will cost you', $page);
        $this->assertStringContainsString('NIN Modification history', $page);
        $this->assertStringContainsString('View receipt', $page);

        // What is closed: the request form, and the terms that front it.
        $this->assertStringNotContainsString('name="field_to_modify"', $page);
        $this->assertStringNotContainsString('id="serviceCostLine"', $page);
        $this->assertStringNotContainsString('data-consent-gate', $page);
        $this->assertStringNotContainsString('Consent and authorisation', $page);

        // A service the owner left alone is untouched by its neighbour's pause.
        $open = $this->actingAs($user)->get('/vtu/manual/ipe_clearance')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-service-paused', $open);
        $this->assertStringContainsString('name="tracking_id"', $open);
    }

    public function test_a_paused_service_refuses_a_request_and_leaves_the_wallet_alone(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->pause('nin_modification');

        // The form is gone from the page, but a tab left open or a POST built by
        // hand still reaches the route, and no agent is working it.
        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', [
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'name',
                'fname' => 'A New',
                'lname' => 'Name',
                'consent_agreed' => '1',
            ])
            ->assertRedirect('/vtu/manual/nin_modification')
            ->assertSessionHas('error');

        $this->assertSame(5_000_000, $this->balance($user));
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_the_validation_page_pauses_the_same_way(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->pause('nin_validation');

        $page = $this->actingAs($user)->get('/vtu/nin-validation')->assertOk()->getContent();

        $this->assertStringContainsString('data-service-paused', $page);
        $this->assertStringContainsString('Service under maintenance', $page);
        $this->assertStringNotContainsString('id="ninValidationForm"', $page);

        $this->actingAs($user)
            ->post('/vtu/nin-validation', ['validation_type' => 'no_record', 'nin' => '12345678901'])
            ->assertRedirect('/vtu/nin-validation')
            ->assertSessionHas('error');

        $this->assertSame(5_000_000, $this->balance($user));
        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_the_hub_marks_the_paused_service_without_hiding_it(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $live = $this->actingAs($user)->get('/identity')->assertOk()->getContent();
        $this->assertStringNotContainsString('Under maintenance', $live);
        $this->assertStringContainsString('NIN Modification', $live);

        $this->pause('nin_modification');

        $page = $this->actingAs($user)->get('/identity')->assertOk()->getContent();

        $this->assertStringContainsString('NIN Modification', $page);
        $this->assertStringContainsString('Under maintenance', $page);
        $this->assertStringContainsString('identity-status-paused', $page);
    }

    public function test_the_board_only_offers_switches_for_services_that_obey_them(): void
    {
        $board = app(ServiceAvailability::class)->board();

        $keys = array_column($board['identity']['items'], 'key');

        $this->assertContains('service_down_identity_nin_modification', $keys);
        $this->assertContains('service_down_identity_nin_validation', $keys);

        // The two verification endpoints answer the provider directly and the BVN
        // retrieval is a card on the BVN page, so a tick over them would lie.
        $this->assertNotContains('service_down_identity_nin_verify', $keys);
        $this->assertNotContains('service_down_identity_bvn_verify', $keys);
        $this->assertNotContains('service_down_identity_bvn_retrieve', $keys);
    }

    private function pause(string $slug): void
    {
        Setting::create([
            'key' => app(ServiceAvailability::class)->switchKey('identity', $slug),
            'value' => '1',
        ]);

        settings_flush_cache();
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
