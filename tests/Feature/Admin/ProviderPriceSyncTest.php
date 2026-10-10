<?php

namespace Tests\Feature\Admin;

use App\Models\ProviderPlanPrice;
use App\Models\User;
use App\Notifications\AdminSystemAlertNotification;
use App\Services\ProviderPlanPriceService;
use App\Support\ServiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProviderPriceSyncTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gsubz.key' => 'test-key']);
        config(['services.gsubz.base' => 'https://api.gsubz.test']);
    }

    public function test_a_bounded_refresh_only_fetches_the_requested_number_of_services(): void
    {
        Http::fake([
            'api.gsubz.test/*' => Http::response([
                'plans' => [['value' => 'plan-1', 'price' => '1000.00', 'display_name' => 'Plan One']],
            ]),
        ]);

        $summary = app(ProviderPlanPriceService::class)->syncProviderPrices(limit: 3);

        $this->assertCount(3, $summary['attempted_services']);
        $this->assertSame([], $summary['failed_services']);
        $this->assertSame(3, $summary['synced_plans']);
        $this->assertSame(
            count(app(ProviderPlanPriceService::class)->pricingServiceSlugs()) - 3,
            $summary['stale'],
            'Services left unrefreshed by the budget must still be reported as stale.'
        );
        Http::assertSentCount(3);
    }

    public function test_a_bounded_refresh_prefers_the_least_recently_synced_services(): void
    {
        $service = app(ProviderPlanPriceService::class);
        $provider = $service->currentProvider();
        $slugs = $service->pricingServiceSlugs();

        // Make every service look freshly synced, then age exactly one of them.
        foreach ($slugs as $slug) {
            ProviderPlanPrice::query()->create([
                'provider' => $provider,
                'service_slug' => $slug,
                'plan_id' => 'stale-probe',
                'plan_name' => 'Probe',
                'provider_price' => 1000,
                'selling_price' => 1200,
                'last_synced_at' => now()->subMinute(),
            ]);
        }

        $oldest = end($slugs);
        ProviderPlanPrice::query()
            ->where('provider', $provider)
            ->where('service_slug', $oldest)
            ->update(['last_synced_at' => now()->subDays(30)]);

        Http::fake([
            'api.gsubz.test/*' => Http::response([
                'plans' => [['value' => 'plan-1', 'price' => '1000.00', 'display_name' => 'Plan One']],
            ]),
        ]);

        $summary = $service->syncProviderPrices(limit: 1);

        $this->assertSame([$oldest], $summary['attempted_services']);
    }

    public function test_a_service_that_the_provider_cannot_serve_is_reported_and_skipped(): void
    {
        Http::fake([
            'api.gsubz.test/*' => Http::response(['error' => 'nope'], 500),
        ]);

        $summary = app(ProviderPlanPriceService::class)->syncProviderPrices(limit: 1);

        $this->assertCount(1, $summary['failed_services']);
        $this->assertSame(0, $summary['synced_plans']);
    }

    public function test_a_plan_the_provider_stopped_listing_is_taken_off_the_shelf(): void
    {
        $service = app(ProviderPlanPriceService::class);
        $provider = $service->currentProvider();
        $this->seedPlan($provider, 'awoof-old', 'Old Awoof', 1500);

        $service->syncPlans('mtn_awoof', [
            ['value' => 'awoof-new', 'price' => '2000.00', 'display_name' => 'New Awoof'],
        ], 'mtn_awoof', $provider, true);

        $this->assertDatabaseHas('provider_plan_prices', [
            'service_slug' => 'mtn_awoof',
            'plan_id' => 'awoof-old',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('provider_plan_prices', [
            'service_slug' => 'mtn_awoof',
            'plan_id' => 'awoof-new',
            'is_active' => true,
        ]);
    }

    public function test_a_shelf_is_only_cleared_when_the_provider_actually_answered(): void
    {
        $service = app(ProviderPlanPriceService::class);
        $provider = $service->currentProvider();
        $this->seedPlan($provider, 'awoof-old', 'Old Awoof', 1500);

        // An outage also arrives as "no plans"; that says nothing about the
        // catalogue, so nothing may be retired and no customer loses a service.
        $service->syncPlans('mtn_awoof', [], 'mtn_awoof', $provider, false);

        $this->assertDatabaseHas('provider_plan_prices', [
            'service_slug' => 'mtn_awoof',
            'plan_id' => 'awoof-old',
            'is_active' => true,
        ]);
        $this->assertSame('available', $this->awoofStateOnMenu());
    }

    public function test_the_data_menu_hides_awoof_once_the_provider_withdraws_it(): void
    {
        Notification::fake();

        $member = User::factory()->create(['email_verified_at' => now()]);
        $service = app(ProviderPlanPriceService::class);
        $this->seedPlan($service->currentProvider(), 'awoof-old', 'Old Awoof', 1500);

        // The provider answers for every service and lists nothing at all.
        Http::fake([
            'api.gsubz.test/*' => Http::response(['plans' => []], 200),
        ]);
        $service->syncProviderPrices(limit: count($service->pricingServiceSlugs()));

        $this->assertSame('unavailable', $this->awoofStateOnMenu());

        $this->be($member);
        $html = $this->get('/vtu/data')->assertOk()->getContent();
        $this->assertStringContainsString('data-awoof-server-state="unavailable"', $html);
        $this->assertStringContainsString('data-awoof-state="unavailable"', $html);

        /* Marking the tile `hidden` only works while something outside Tailwind's
           utility layer agrees to honour it, because the tile's own display rule
           sits unlayered and outranks the utility whatever the specificity. */
        $this->assertStringContainsString('class="reference-service-tile hidden"', $html);

        /* The robot has to keep saying it for as long as the outage lasts, so
           the words are on the page itself rather than in a bubble that times
           out. This is the sentence the owner asked for, service named. */
        $this->assertStringContainsString('data-outage-notice', $html);
        $this->assertStringContainsString('<span class="svc-outage-chip" data-outage-slug="mtn_awoof"', $html);
        $this->assertStringContainsString(
            'MTN Awoof Data (Cheap) is not available right now. Service Under Maintenance. We will inform you when it is back.',
            $html
        );
        $this->assertStringNotContainsString('setTimeout(hideGuide', $html);

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= file_get_contents($file);
        }
        $this->assertStringContainsString('.reference-service-tile.hidden{display:none}', $css);
        $this->assertStringContainsString('.svc-outage.hidden{display:none}', $css);

        $this->get('/vtu/data/mtn_awoof')
            ->assertRedirect(route('vtu.data'))
            ->assertSessionHas('error');
    }

    public function test_the_admin_is_told_when_the_provider_withdraws_the_cheap_plans(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        $service = app(ProviderPlanPriceService::class);
        $this->seedPlan($service->currentProvider(), 'awoof-old', 'Old Awoof', 1500);

        Http::fake([
            'api.gsubz.test/*' => Http::response(['plans' => []], 200),
        ]);
        $service->syncProviderPrices(limit: count($service->pricingServiceSlugs()));

        Notification::assertSentTo(
            $admin,
            AdminSystemAlertNotification::class,
            function ($notification) use ($admin): bool {
                $alert = $notification->toArray($admin);

                return str_contains($alert['title'], 'Awoof') && $alert['severity'] === 'critical';
            }
        );
    }

    public function test_the_admin_is_told_when_the_cheap_plans_appear_again(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        $service = app(ProviderPlanPriceService::class);
        // The shelf is empty because the provider took this plan off earlier.
        $this->seedPlan($service->currentProvider(), 'awoof-old', 'Old Awoof', 1500, false);

        Http::fake([
            'api.gsubz.test/*' => Http::response([
                'plans' => [['value' => 'awoof-old', 'price' => '1500.00', 'display_name' => 'Old Awoof']],
            ], 200),
        ]);
        $service->syncProviderPrices(limit: count($service->pricingServiceSlugs()));

        Notification::assertSentTo(
            $admin,
            AdminSystemAlertNotification::class,
            function ($notification) use ($admin): bool {
                $alert = $notification->toArray($admin);

                return str_contains($alert['title'], 'Awoof') && $alert['severity'] === 'info';
            }
        );
    }

    public function test_the_same_answer_twice_is_not_news(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        $service = app(ProviderPlanPriceService::class);
        $this->seedPlan($service->currentProvider(), 'awoof-old', 'Old Awoof', 1500);

        Http::fake([
            'api.gsubz.test/*' => Http::response([
                'plans' => [['value' => 'awoof-old', 'price' => '1500.00', 'display_name' => 'Old Awoof']],
            ], 200),
        ]);
        $service->syncProviderPrices(limit: count($service->pricingServiceSlugs()));

        Notification::assertNothingSent();
    }

    public function test_a_silent_sweep_announces_nothing(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        $service = app(ProviderPlanPriceService::class);
        $this->seedPlan($service->currentProvider(), 'awoof-old', 'Old Awoof', 1500);

        Http::fake([
            'api.gsubz.test/*' => Http::response(['error' => 'down'], 500),
        ]);
        $service->syncProviderPrices(limit: count($service->pricingServiceSlugs()));

        Notification::assertNothingSent();
        $this->assertDatabaseHas('provider_plan_prices', [
            'service_slug' => 'mtn_awoof',
            'plan_id' => 'awoof-old',
            'is_active' => true,
        ]);
    }

    private function seedPlan(string $provider, string $planId, string $name, float $price, bool $active = true): void
    {
        ProviderPlanPrice::query()->create([
            'provider' => $provider,
            'service_slug' => 'mtn_awoof',
            'provider_service_id' => 'mtn_awoof',
            'plan_id' => $planId,
            'plan_name' => $name,
            'provider_price' => $price,
            'selling_price' => $price,
            'is_active' => $active,
            'last_synced_at' => now()->subDays(30),
        ]);
    }

    private function awoofStateOnMenu(): string
    {
        if (app(ServiceAvailability::class)->isDown('mtn_awoof', 'data')) {
            return 'unavailable';
        }

        return app(ProviderPlanPriceService::class)->activePlanCount('mtn_awoof') > 0 ? 'available' : 'unknown';
    }
}
