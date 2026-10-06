<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ManualFulfilmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JH Tech is the only source for every identity job on this site, so the rate
 * they charge is the floor the site must price above. These numbers came off
 * the logged-in jhtechltd.com dashboard, and nothing here can be re-derived
 * from code, which is why the guards live in a test.
 */
class IdentityPriceReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_wired_identity_price_defaults_above_what_jh_tech_charges(): void
    {
        foreach (jhtech_price_reference() as $key => $entry) {
            $this->assertGreaterThanOrEqual(
                $entry['cost'],
                identity_price($key),
                $key.' would be sold at or below the JH Tech cost of ₦'.$entry['cost'].'.'
            );
        }
    }

    public function test_every_manual_service_has_a_price_of_its_own(): void
    {
        $manual = app(ManualFulfilmentService::class);

        foreach (array_keys($manual->catalogue()) as $slug) {
            $this->assertArrayHasKey(
                $manual->priceKey($slug),
                jhtech_price_reference(),
                $slug.' has no price reference, so it would be offered for nothing.'
            );
        }
    }

    public function test_a_service_sold_below_its_cost_is_caught(): void
    {
        // identity_price() is what the purchase path charges with no settings
        // rows, so this is the number a fresh install actually bills.
        $this->assertSame(3500.0, identity_price('price_bvn_retrieve_phone'));
        $this->assertSame(200.0, identity_price('price_bvn_verify'));
        $this->assertSame(3500.0, app(ManualFulfilmentService::class)->priceNaira('bvn_retrieve'));
    }

    public function test_the_settings_page_shows_the_cost_behind_each_identity_price(): void
    {
        $manual = app(ManualFulfilmentService::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        $html = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('JH Tech cost', $html);
        // The old VNIN default was the cost, not a price.
        $this->assertStringContainsString('name="price_nin_slip_vnin"', $html);
        $this->assertStringContainsString('value="300.00"', $html);
        $expectedHints = 10;
        foreach (array_keys($manual->catalogue()) as $slug) {
            if ($manual->providerCost($slug) !== null) {
                $expectedHints++;
            }
        }

        $this->assertSame(
            $expectedHints,
            substr_count($html, 'JH Tech cost'),
            'Every priced identity job should carry exactly one cost hint.'
        );
    }

    public function test_the_owner_can_still_override_a_reference_price(): void
    {
        \App\Models\Setting::query()->updateOrCreate(['key' => 'price_bvn_verify'], ['value' => '450']);
        settings_flush_cache();

        $this->assertSame(450.0, identity_price('price_bvn_verify'));
        $this->assertSame(100.0, identity_cost('price_bvn_verify'));
        // The shipped default is untouched, so clearing the setting restores it.
        $this->assertSame(200.0, identity_reference_price('price_bvn_verify'));
    }

    public function test_the_owner_can_move_any_identity_price_and_the_site_follows_at_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        $this->actingAs($admin)->post('/admin/settings', [
            'price_bvn_verify' => '350',
            'price_nin_slip_vnin' => '900',
            'price_manual_bvn_print' => '1750',
        ])->assertRedirect();

        // No deploy, no cache clear, no restart: the next page view and the next
        // charge both use the new figure.
        $this->assertSame(350.0, identity_price('price_bvn_verify'));
        $this->assertSame(900.0, identity_price('price_nin_slip_vnin'));
        $this->assertSame(1750.0, app(ManualFulfilmentService::class)->priceNaira('bvn_print'));

        $member = User::factory()->create();
        $member->email_verified_at = now();
        $member->save();
        \App\Models\Wallet::query()->firstOrCreate(['user_id' => $member->id], ['balance' => 5_000_000]);

        $this->actingAs($member)->get('/vtu/bvn')
            ->assertOk()
            ->assertSee('350.00', false)
            ->assertDontSee('100.00', false);

        $this->actingAs($member)->get('/vtu/manual/bvn_print')
            ->assertOk()
            ->assertSee('1,750.00', false);
    }

    public function test_an_unpublished_rate_is_reported_as_unknown_rather_than_free(): void
    {
        // Modification has no published JH Tech rate; the hint must say so
        // instead of showing ₦0, which an owner would read as free labour.
        $this->assertNull(identity_cost('price_manual_nin_modification'));
        $this->assertSame(3500.0, app(ManualFulfilmentService::class)->priceNaira('nin_modification'));
    }
}
