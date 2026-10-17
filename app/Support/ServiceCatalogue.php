<?php

namespace App\Support;

use App\Models\Service;

class ServiceCatalogue
{
    public function dataServices(): array
    {
        $fallback = [
            'mtn_awoof' => 'MTN Awoof Data (Cheap)',
            'mtn_gifting' => 'MTN Data (Gifting)',
            'mtn_sme' => 'MTN Data (SME)',
            'mtn_cg' => 'MTN Data (Corporate)',
            'mtn_cg_lite' => 'MTN Data (CG Lite)',
            'mtn_coupon' => 'MTN Coupon',
            'mtncg' => 'MTN CG',
            'airtel_sme' => 'Airtel Data (SME)',
            'airtel_cg' => 'Airtel Data (CG)',
            'airtel_gifting' => 'Airtel Data (Gifting)',
            'glo_data' => 'Glo Data',
            'glo_sme' => 'Glo Data (SME)',
            'etisalat_data' => '9mobile Data',
        ];

        $displayOrder = [
            'mtn_awoof',
            'mtn_gifting',
            'mtn_sme',
            'mtn_cg',
            'mtn_cg_lite',
            'mtn_coupon',
            'mtncg',
            'airtel_sme',
            'airtel_cg',
            'airtel_gifting',
            'glo_data',
            'glo_sme',
            'etisalat_data',
        ];

        $serviceDefaultEnabled = [
            'mtn_awoof' => '1',
            'mtn_gifting' => '1',
            'mtn_sme' => '1',
            'mtn_cg' => '0',
            'mtn_cg_lite' => '0',
            'mtn_coupon' => '0',
            'mtncg' => '0',
            'airtel_sme' => '1',
            'airtel_cg' => '1',
            'airtel_gifting' => '1',
            'glo_data' => '1',
            'glo_sme' => '1',
            'etisalat_data' => '1',
        ];

        $customServices = $this->parseServicesSetting('services_data');
        $baseServices = !empty($customServices) ? $customServices : $fallback;
        $expectedSlugs = array_values(array_unique(array_merge(array_keys($fallback), array_keys($baseServices))));

        $dbServices = Service::query()
            ->whereIn('slug', $expectedSlugs)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->toArray();

        $services = array_replace($baseServices, $dbServices);

        foreach ($serviceDefaultEnabled as $slug => $defaultEnabled) {
            $enabled = (string) setting('data_service_enabled_' . $slug, $defaultEnabled) === '1';
            if (!$enabled) {
                unset($services[$slug]);
            }
        }

        $orderedServices = [];
        foreach ($displayOrder as $slug) {
            if (!array_key_exists($slug, $services)) {
                continue;
            }
            $orderedServices[$slug] = $services[$slug];
            unset($services[$slug]);
        }

        foreach ($services as $slug => $label) {
            $orderedServices[$slug] = $label;
        }

        return $orderedServices;
    }

    public function airtimeServices(): array
    {
        $services = $this->parseServicesSetting('services_airtime');
        if (!empty($services)) {
            return $services;
        }

        return [
            'mtn' => 'MTN Airtime',
            'airtel' => 'Airtel Airtime',
            'glo' => 'Glo Airtime',
            'etisalat' => '9mobile Airtime',
        ];
    }

    public function cableServices(): array
    {
        $services = $this->parseServicesSetting('services_cable');
        if (!empty($services)) {
            return $services;
        }

        return [
            'dstv' => 'DSTV Subscription',
            'gotv' => 'GOTV Subscription',
            'startimes' => 'Startimes Subscription',
        ];
    }

    public function electricityServices(): array
    {
        $services = $this->parseServicesSetting('services_electricity');
        if (!empty($services)) {
            return $services;
        }

        // These slugs must match the `services` table, because the same string
        // is what goes to the provider as serviceID when no service map is set.
        return [
            'abuja-electric' => 'Abuja Electric (AEDC)',
            'eko-electric' => 'Eko Electric (EKEDC)',
            'ibadan-electric' => 'Ibadan Electric (IBEDC)',
            'ikeja-electric' => 'Ikeja Electric (IKEDC)',
            'jos-electric' => 'Jos Electric (JED)',
            'kaduna-electric' => 'Kaduna Electric (KAEDCO)',
            'kano-electric' => 'Kano Electric (KEDCO)',
            'phed-electric' => 'Port Harcourt Electric (PHED)',
            'yola-electric' => 'Yola Electric (YEDC)',
            'benin-electric' => 'Benin Electric (BEDC)',
            'enugu-electric' => 'Enugu Electric (EEDC)',
        ];
    }

    public function educationServices(): array
    {
        $services = $this->parseServicesSetting('services_education');
        if (!empty($services)) {
            return $services;
        }

        return [
            'jamb' => 'JAMB PIN (UTME & Direct Entry)',
            'waec' => 'WAEC Result Checker PIN',
            'neco' => 'NECO Result Checker PIN',
            'nabteb' => 'NABTEB Result Checker PIN',
        ];
    }

    public function parseServicesSetting(string $key): array
    {
        $raw = trim((string) setting($key, ''));
        if ($raw === '') return [];

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $parts = preg_split('/\\s*\\|\\s*/', $line, 2);
            if (count($parts) < 2) {
                $parts = preg_split('/\\s*=\\s*/', $line, 2);
            }

            $id = trim($parts[0] ?? '');
            if ($id === '') continue;
            if (preg_match('/\s/', $id)) {
                $id = preg_replace('/\s+/', '_', strtolower($id)) ?? $id;
            }
            $label = trim($parts[1] ?? $id);

            $out[$id] = $label !== '' ? $label : $id;
        }

        return $out;
    }

    public function rechargeCardNetworkLabels(): array
    {
        $configured = $this->parseServicesSetting('recharge_card_networks');
        $cleaned = [];

        foreach ($configured as $key => $label) {
            $slug = strtolower(trim((string) $key));
            if ($slug === '') {
                continue;
            }
            $cleaned[$slug] = trim((string) $label) !== '' ? trim((string) $label) : strtoupper($slug);
        }

        if (!empty($cleaned)) {
            return $cleaned;
        }

        return [
            'mtn' => 'MTN',
            'airtel' => 'Airtel',
            'glo' => 'Glo',
            'etisalat' => '9mobile',
        ];
    }

    public function rechargeCardValues(): array
    {
        $raw = trim((string) setting('recharge_card_values', ''));
        if ($raw === '') {
            return [100, 200, 400, 500, 1000];
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $values = [];

        foreach ($parts as $part) {
            $line = trim((string) $part);
            if ($line === '') {
                continue;
            }

            $first = trim((string) preg_split('/\\|/', $line, 2)[0]);
            $digits = preg_replace('/[^0-9]/', '', $first);
            if ($digits === '') {
                continue;
            }

            $amount = (int) $digits;
            if ($amount > 0) {
                $values[] = $amount;
            }
        }

        $values = array_values(array_unique($values));
        if (empty($values)) {
            return [100, 200, 400, 500, 1000];
        }

        sort($values);
        return $values;
    }
}
