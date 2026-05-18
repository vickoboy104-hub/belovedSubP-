<?php

namespace Tests\Unit;

use App\Http\Controllers\VtuController;
use App\Models\Setting;
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

    public function test_data_plan_price_override_replaces_customer_price_only(): void
    {
        Setting::updateOrCreate(['key' => 'data_plan_price_overrides'], ['value' => "mtn_awoof|452|250\nmtn_awoof|453|600"]);
        settings_flush_cache();

        $plans = $this->applyDataPlanSellingPrices('mtn_awoof', [
            ['value' => '452', 'price' => '209', 'display_name' => '1GB - 1 day'],
            ['value' => '999', 'price' => '1000', 'display_name' => '5GB - 7 days'],
        ]);

        $this->assertSame(209.0, $plans[0]['provider_price']);
        $this->assertSame(250.0, $plans[0]['selling_price']);
        $this->assertSame(250.0, $plans[0]['price']);
        $this->assertTrue($plans[0]['price_override_applied']);

        $this->assertSame(1000.0, $plans[1]['provider_price']);
        $this->assertSame(1000.0, $plans[1]['selling_price']);
        $this->assertSame(1000.0, $plans[1]['price']);
        $this->assertFalse($plans[1]['price_override_applied']);
    }

    private function resolveProviderServiceId(string $slug): string
    {
        $controller = app(VtuController::class);
        $method = (new ReflectionClass($controller))->getMethod('providerServiceId');
        $method->setAccessible(true);

        return $method->invoke($controller, $slug);
    }

    private function applyDataPlanSellingPrices(string $service, array $plans): array
    {
        $controller = app(VtuController::class);
        $method = (new ReflectionClass($controller))->getMethod('applyDataPlanSellingPrices');
        $method->setAccessible(true);

        return $method->invoke($controller, $plans, $service);
    }
}
