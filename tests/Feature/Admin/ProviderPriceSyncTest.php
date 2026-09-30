<?php

namespace Tests\Feature\Admin;

use App\Models\ProviderPlanPrice;
use App\Services\ProviderPlanPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
