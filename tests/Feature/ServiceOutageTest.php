<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProviderPlanPrice;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A customer used to be able to sit at a form for a network that is not selling
 * anything, pay for it, and get nothing. The verdict now has one source, and
 * every layer asks it: the menu drops the button, the page reads the outage out
 * and keeps reading it, and the checkout refuses the money.
 */
class ServiceOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_network_the_owner_took_down_has_no_button_and_the_robot_says_so(): void
    {
        $member = $this->member();
        $this->takeDown('service_down_airtime_glo');

        $html = $this->actingAs($member)->get('/vtu/airtime')->assertOk()->getContent();

        $this->assertStringNotContainsString('vtu/airtime/glo', $html);
        $this->assertStringContainsString('vtu/airtime/mtn', $html);

        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="glo"', $html);
        $this->assertStringContainsString(
            'Glo Airtime is not available right now. Service Under Maintenance. We will inform you when it is back.',
            $html
        );
    }

    public function test_a_down_network_is_refused_at_its_form_and_at_the_checkout(): void
    {
        $member = $this->member(50_000_00);
        $this->takeDown('service_down_airtime_glo');

        $this->actingAs($member)->get('/vtu/airtime/glo')
            ->assertRedirect(route('vtu.airtime'))
            ->assertSessionHas('error', 'Glo Airtime is not available right now. Service Under Maintenance. We will inform you when it is back.');

        $response = $this->actingAs($member)->postJson('/vtu/airtime/buy', [
            'service_id' => 'glo',
            'phone' => '08012345678',
            'amount' => 1000,
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('not available right now', $response->json('message'));

        // No order, and not a kobo gone from the wallet.
        $this->assertSame(0, Order::query()->where('user_id', $member->id)->count());
        $this->assertSame(50_000_00, (int) $member->fresh()->wallet->balance);
    }

    public function test_taking_mtn_airtime_down_leaves_mtn_recharge_cards_sellable(): void
    {
        $member = $this->member();
        $this->takeDown('service_down_airtime_mtn');

        $this->actingAs($member)->get('/vtu/airtime')
            ->assertOk()
            ->assertSee('Airtime', false);

        $cards = $this->actingAs($member)->get('/vtu/recharge-card')->assertOk()->getContent();
        $this->assertStringNotContainsString('<span class="svc-outage-chip" data-outage-slug="mtn"', $cards);
        $this->assertStringContainsString('data-network="mtn"', $cards);

        $this->actingAs($member)->get('/vtu/airtime/mtn')
            ->assertRedirect(route('vtu.airtime'));
    }

    public function test_the_recharge_card_page_hides_a_down_network_and_keeps_the_rest(): void
    {
        $member = $this->member();
        $this->takeDown('service_down_card_glo');

        $html = $this->actingAs($member)->get('/vtu/recharge-card')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-network="glo"', $html);
        $this->assertStringContainsString('data-network="mtn"', $html);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="glo"', $html);

        // The form's own dropdown must not offer what the tiles no longer show.
        $this->assertStringNotContainsString('<option value="glo">', $html);
    }

    public function test_the_whole_recharge_card_form_goes_when_no_network_prints(): void
    {
        $member = $this->member();
        foreach (['mtn', 'airtel', 'glo', 'etisalat'] as $network) {
            $this->takeDown('service_down_card_' . $network);
        }

        $html = $this->actingAs($member)->get('/vtu/recharge-card')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="rechargeCardForm"', $html);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="etisalat"', $html);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="glo"', $html);
    }

    public function test_the_owner_can_write_his_own_outage_words(): void
    {
        $member = $this->member();
        $this->takeDown('service_down_cable_dstv');
        Setting::query()->updateOrCreate(
            ['key' => 'service_maintenance_message'],
            ['value' => 'Our cable partner is down this morning. Pleases try again later.']
        );
        settings_flush_cache();

        $html = $this->actingAs($member)->get('/vtu/cable')->assertOk()->getContent();

        $this->assertStringContainsString('Our cable partner is down this morning', $html);
        $this->assertStringNotContainsString('DSTV Subscription is not available right now', $html);
    }

    public function test_a_service_the_provider_started_listing_again_is_not_announced(): void
    {
        $member = $this->member();
        ProviderPlanPrice::query()->create([
            'provider' => 'gsubz',
            'service_slug' => 'mtn_awoof',
            'provider_service_id' => 'mtn_awoof',
            'plan_id' => 'awoof-1',
            'plan_name' => 'Awoof 1GB',
            'provider_price' => 1200,
            'selling_price' => 1400,
            'is_active' => true,
            'last_synced_at' => now()->subMinute(),
        ]);

        $html = $this->actingAs($member)->get('/vtu/data')->assertOk()->getContent();

        $this->assertStringNotContainsString('<span class="svc-outage-chip" data-outage-slug="mtn_awoof"', $html);
        $this->assertStringContainsString('data-awoof-server-state="available"', $html);
        $this->assertStringContainsString('vtu/data/mtn_awoof', $html);
    }

    public function test_a_service_the_provider_withdrew_is_announced_on_every_layer(): void
    {
        $member = $this->member(50_000_00);
        ProviderPlanPrice::query()->create([
            'provider' => 'gsubz',
            'service_slug' => 'glo_data',
            'provider_service_id' => 'glo_data',
            'plan_id' => 'glo-old',
            'plan_name' => 'Glo 1GB',
            'provider_price' => 1000,
            'selling_price' => 1200,
            'is_active' => false,
            'last_synced_at' => now()->subMinute(),
        ]);

        $menu = $this->actingAs($member)->get('/vtu/data')->assertOk()->getContent();
        $this->assertStringNotContainsString('vtu/data/glo_data', $menu);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="glo_data"', $menu);

        $this->actingAs($member)->get('/vtu/data/glo_data')->assertRedirect(route('vtu.data'));

        $this->actingAs($member)->postJson('/vtu/data/buy', [
            'service_id' => 'glo_data',
            'plan' => 'glo-old',
            'phone' => '08012345678',
            'amount' => 1200,
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertSame(0, Order::query()->where('user_id', $member->id)->count());
    }

    public function test_the_education_and_light_shelves_announce_what_is_off(): void
    {
        $member = $this->member();
        $this->takeDown('service_down_exam_waec');
        $this->takeDown('service_down_electricity_ikeja-electric');

        $exam = $this->actingAs($member)->get('/vtu/exam')->assertOk()->getContent();
        $this->assertStringNotContainsString('vtu/exam/waec', $exam);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="waec"', $exam);

        $electricity = $this->actingAs($member)->get('/vtu/electricity')->assertOk()->getContent();
        $this->assertStringNotContainsString('vtu/electricity/ikeja-electric', $electricity);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="ikeja-electric"', $electricity);
    }

    public function test_the_admin_board_offers_every_service_the_customer_can_see(): void
    {
        $admin = $this->member(isAdmin: true);

        $html = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('group-service-availability', $html);
        $this->assertStringContainsString('name="service_down_airtime_mtn"', $html);
        $this->assertStringContainsString('name="service_down_data_mtn_awoof"', $html);
        $this->assertStringContainsString('name="service_down_electricity_ikeja-electric"', $html);
        $this->assertStringContainsString('name="service_maintenance_message"', $html);
    }

    public function test_saving_the_board_takes_a_service_off_the_shelf(): void
    {
        $admin = $this->member(isAdmin: true);
        $member = $this->member();

        $this->actingAs($admin)->post('/admin/settings', [
            'service_down_exam_neco' => '1',
        ])->assertRedirect();

        $this->assertSame('1', Setting::query()->where('key', 'service_down_exam_neco')->value('value'));

        $html = $this->actingAs($member)->get('/vtu/exam')->assertOk()->getContent();
        $this->assertStringNotContainsString('vtu/exam/neco', $html);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="neco"', $html);

        // Unticked services are written back as '0', so nothing goes down by accident.
        $this->assertSame('0', Setting::query()->where('key', 'service_down_exam_jamb')->value('value'));
    }

    public function test_the_board_marks_a_supplier_withdrawal_without_ticking_it(): void
    {
        $admin = $this->member(isAdmin: true);
        ProviderPlanPrice::query()->create([
            'provider' => 'gsubz',
            'service_slug' => 'glo_data',
            'provider_service_id' => 'glo_data',
            'plan_id' => 'glo-old',
            'plan_name' => 'Glo 1GB',
            'provider_price' => 1000,
            'selling_price' => 1200,
            'is_active' => false,
            'last_synced_at' => now()->subMinute(),
        ]);
        $this->takeDown('service_down_airtime_glo');

        $html = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();

        /* The supplier taking a plan off the shelf is news the owner should see,
           but it is not his switch to flip: a tick there would keep the service
           buried long after the supplier put it back. */
        $this->assertStringContainsString('>supplier<', $html);
        $this->assertStringNotContainsString('checked', $this->switchTag($html, 'service_down_data_glo_data'));
        $this->assertStringContainsString('checked', $this->switchTag($html, 'service_down_airtime_glo'));

        $this->actingAs($admin)->post('/admin/settings', [])->assertRedirect();
        $this->assertSame('0', Setting::query()->where('key', 'service_down_data_glo_data')->value('value'));
    }

    private function switchTag(string $html, string $key): string
    {
        $this->assertSame(
            1,
            preg_match('/<input[^>]*name="' . preg_quote($key, '/') . '"[^>]*>/', $html, $tag),
            "The board never offered the {$key} switch."
        );

        return $tag[0];
    }

    private function takeDown(string $key): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => '1']);
        settings_flush_cache();
    }

    private function member(int $balanceKobo = 0, bool $isAdmin = false): User
    {
        $user = User::factory()->create(['is_admin' => $isAdmin]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $balanceKobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $balanceKobo]);

        return $user->fresh();
    }
}
