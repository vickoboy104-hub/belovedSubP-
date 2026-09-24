<?php

namespace Tests\Unit;

use App\Http\Controllers\VtuController;
use App\Models\ProviderPlanPrice;
use App\Models\Setting;
use App\Services\ProviderPlanPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ProviderServiceIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_like_service_map_value_falls_back_to_slug(): void
    {
        Setting::updateOrCreate(['key' => 'provider'], ['value' => 'gsubz']);
        Setting::updateOrCreate(['key' => 'service_map_mtn_awoof'], ['value' => '209']);
        settings_flush_cache();

        $this->assertSame('mtn_awoof', $this->resolveProviderServiceId('mtn_awoof'));
    }

    public function test_valid_service_map_value_is_still_used(): void
    {
        Setting::updateOrCreate(['key' => 'provider'], ['value' => 'gsubz']);
        Setting::updateOrCreate(['key' => 'service_map_mtn_awoof'], ['value' => 'mtn_awoof']);
        settings_flush_cache();

        $this->assertSame('mtn_awoof', $this->resolveProviderServiceId('mtn_awoof'));
    }

    public function test_price_like_profile_map_value_falls_back_to_slug(): void
    {
        Setting::updateOrCreate(['key' => 'provider'], ['value' => 'gsubz']);
        Setting::updateOrCreate(['key' => 'service_map_profile_gsubz'], ['value' => "mtn_awoof|509\nmtn_sme|mtn_sme"]);
        settings_flush_cache();

        $this->assertSame('mtn_awoof', $this->resolveProviderServiceId('mtn_awoof'));
        $this->assertSame('mtn_sme', $this->resolveProviderServiceId('mtn_sme'));
    }

    public function test_provider_plan_prices_are_stored_hidden_and_customer_gets_selling_price(): void
    {
        Setting::updateOrCreate(['key' => 'provider'], ['value' => 'gsubz']);
        settings_flush_cache();

        $service = app(ProviderPlanPriceService::class);
        $plans = [
            ['value' => '452', 'price' => '209', 'display_name' => '1GB - 1 day'],
            ['value' => '999', 'price' => '1000', 'display_name' => '5GB - 7 days'],
        ];

        $service->syncPlans('mtn_awoof', $plans, 'mtn_awoof', 'gsubz');
        $stored = ProviderPlanPrice::query()->where('service_slug', 'mtn_awoof')->where('plan_id', '452')->firstOrFail();
        $this->assertSame(209.0, (float) $stored->provider_price);
        $this->assertSame(209.0, (float) $stored->selling_price);

        $service->setSellingPrice($stored, 300);

        $customerPlans = $service->customerPlans($plans, 'mtn_awoof', 'mtn_awoof', 'gsubz');
        $this->assertSame(300.0, (float) $customerPlans[0]['price']);
        $this->assertSame(300.0, (float) $customerPlans[0]['selling_price']);
        $this->assertArrayNotHasKey('provider_price', $customerPlans[0]);

        $pricing = $service->pricingForPlan('mtn_awoof', '452', $plans, 'mtn_awoof', 'gsubz');
        $this->assertSame(209.0, $pricing['provider_price']);
        $this->assertSame(300.0, $pricing['selling_price']);
        $this->assertTrue($pricing['custom']);
    }

    private function resolveProviderServiceId(string $slug): string
    {
        $controller = app(VtuController::class);
        $method = (new ReflectionClass($controller))->getMethod('providerServiceId');
        $method->setAccessible(true);

        return $method->invoke($controller, $slug);
    }

}
