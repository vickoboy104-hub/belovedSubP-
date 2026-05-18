<?php

namespace App\Services;

use App\Models\ProviderPlanPrice;
use Illuminate\Support\Collection;

class ProviderPlanPriceService
{
    public function currentProvider(): string
    {
        $provider = trim((string) setting('provider', 'gsubz'));

        return $provider !== '' ? $provider : 'gsubz';
    }

    public function providerServiceId(string $serviceSlug, ?string $provider = null): string
    {
        $slug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        if ($slug === '') {
            return $serviceSlug;
        }

        $serviceAliases = [
            'canva_pro' => 'canva',
            'canva-pro' => 'canva',
            'canvapro' => 'canva',
            'canva_premium' => 'canva',
        ];
        $slug = $serviceAliases[$slug] ?? $slug;

        $provider = trim((string) ($provider ?? $this->currentProvider()));
        if ($provider === '') {
            $provider = 'gsubz';
        }

        $profileMap = $this->parseServiceMapProfile('service_map_profile_' . $provider);
        if (array_key_exists($slug, $profileMap)) {
            $profileServiceId = $this->usableProviderServiceId($profileMap[$slug]);
            if ($profileServiceId !== null) {
                return $profileServiceId;
            }
        }

        $providerSpecific = $this->usableProviderServiceId(setting('service_map_' . $provider . '_' . $slug, ''));
        if ($providerSpecific !== null) {
            return $providerSpecific;
        }

        $legacy = $this->usableProviderServiceId(setting('service_map_' . $slug, ''));

        return $legacy !== null ? $legacy : $slug;
    }

    /**
     * @param array<int, mixed> $plans
     * @return Collection<int, ProviderPlanPrice>
     */
    public function syncPlans(string $serviceSlug, array $plans, ?string $providerServiceId = null, ?string $provider = null): Collection
    {
        $provider = trim((string) ($provider ?? $this->currentProvider()));
        $serviceSlug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        $providerServiceId = trim((string) ($providerServiceId ?? $this->providerServiceId($serviceSlug, $provider)));
        $synced = collect();

        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planId = $this->planId($plan);
            $providerPrice = $this->planProviderPrice($plan);
            if ($serviceSlug === '' || $planId === '' || $providerPrice <= 0) {
                continue;
            }

            $row = ProviderPlanPrice::query()->firstOrNew([
                'provider' => $provider,
                'service_slug' => $serviceSlug,
                'plan_id' => $planId,
            ]);

            $row->provider_service_id = $providerServiceId !== '' ? $providerServiceId : null;
            $row->plan_name = $this->planName($plan, $planId);
            $row->provider_price = $providerPrice;

            if (!$row->exists || !$row->selling_price_is_custom || (float) $row->selling_price <= 0) {
                $row->selling_price = $providerPrice;
                $row->selling_price_is_custom = false;
            }

            $row->is_active = true;
            $row->raw_plan = $plan;
            $row->last_synced_at = now();
            $row->save();

            $synced->push($row->refresh());
        }

        return $synced;
    }

    /**
     * @param array<int, mixed> $plans
     * @return array<int, mixed>
     */
    public function customerPlans(array $plans, string $serviceSlug, ?string $providerServiceId = null, ?string $provider = null): array
    {
        $provider = trim((string) ($provider ?? $this->currentProvider()));
        $serviceSlug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        $this->syncPlans($serviceSlug, $plans, $providerServiceId, $provider);

        $rows = ProviderPlanPrice::query()
            ->where('provider', $provider)
            ->where('service_slug', $serviceSlug)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (ProviderPlanPrice $row) => $this->normalizeKey($row->plan_id));

        $customerPlans = [];
        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planId = $this->planId($plan);
            if ($planId === '') {
                continue;
            }

            $row = $rows->get($this->normalizeKey($planId));
            $sellingPrice = $row ? (float) $row->selling_price : $this->planProviderPrice($plan);
            if ($sellingPrice <= 0) {
                continue;
            }

            $plan['price'] = $sellingPrice;
            $plan['amount'] = $sellingPrice;
            $plan['plan_amount'] = $sellingPrice;
            $plan['planAmount'] = $sellingPrice;
            $plan['cost'] = $sellingPrice;
            $plan['selling_price'] = $sellingPrice;
            unset($plan['provider_price'], $plan['original_price']);

            $customerPlans[] = $plan;
        }

        return $customerPlans;
    }

    /**
     * @param array<int, mixed> $plans
     * @return array{provider_price: float, selling_price: float, custom: bool}|null
     */
    public function pricingForPlan(string $serviceSlug, string $planId, array $plans, ?string $providerServiceId = null, ?string $provider = null): ?array
    {
        $provider = trim((string) ($provider ?? $this->currentProvider()));
        $serviceSlug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        $this->syncPlans($serviceSlug, $plans, $providerServiceId, $provider);

        $row = ProviderPlanPrice::query()
            ->where('provider', $provider)
            ->where('service_slug', $serviceSlug)
            ->where('plan_id', $planId)
            ->where('is_active', true)
            ->first();

        if (!$row || (float) $row->provider_price <= 0 || (float) $row->selling_price <= 0) {
            return null;
        }

        return [
            'provider_price' => (float) $row->provider_price,
            'selling_price' => (float) $row->selling_price,
            'custom' => (bool) $row->selling_price_is_custom,
        ];
    }

    public function setSellingPrice(ProviderPlanPrice $row, float $sellingPrice): void
    {
        $row->selling_price = max(0, $sellingPrice);
        $row->selling_price_is_custom = abs(((float) $row->selling_price) - ((float) $row->provider_price)) > 0.004;
        $row->save();
    }

    public function planId(array $plan): string
    {
        foreach (['plan_id', 'planID', 'planId', 'value', 'code', 'id', 'plan', 'variation_code'] as $key) {
            if (array_key_exists($key, $plan) && trim((string) $plan[$key]) !== '') {
                return trim((string) $plan[$key]);
            }
        }

        return '';
    }

    public function planProviderPrice(array $plan): float
    {
        foreach (['provider_price', 'original_price', 'price', 'amount', 'plan_amount', 'planAmount', 'cost'] as $key) {
            if (!array_key_exists($key, $plan)) {
                continue;
            }

            $amount = $this->parseMoneyAmount($plan[$key]);
            if ($amount !== null) {
                return $amount;
            }
        }

        return 0.0;
    }

    public function parseMoneyAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $cleaned = preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $value));
        if ($cleaned === null || $cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    private function planName(array $plan, string $planId): string
    {
        foreach (['displayName', 'display_name', 'name', 'plan_name', 'planName', 'description', 'product', 'bundle', 'plan'] as $key) {
            if (array_key_exists($key, $plan) && trim((string) $plan[$key]) !== '') {
                return trim((string) $plan[$key]);
            }
        }

        return $planId;
    }

    private function usableProviderServiceId(mixed $value): ?string
    {
        $serviceId = trim((string) $value);
        if ($serviceId === '') {
            return null;
        }

        if (preg_match('/^(?:\x{20A6}|N|NGN)?\s*\d+(?:[,.]\d+)?\s*(?:naira)?$/iu', $serviceId)) {
            return null;
        }

        return $serviceId;
    }

    private function parseServiceMapProfile(string $key): array
    {
        $raw = trim((string) setting($key, ''));
        if ($raw === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*(?:=>|=|\|)\s*/', $line, 2) ?: [];
            $slug = $this->normalizeKey($parts[0] ?? '');
            $serviceId = trim((string) ($parts[1] ?? ''));
            if ($slug !== '' && $serviceId !== '') {
                $map[$slug] = $serviceId;
            }
        }

        return $map;
    }

    private function normalizeKey(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
