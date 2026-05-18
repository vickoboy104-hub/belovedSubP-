<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        // pull settings into a simple key => value array
        $settings = Setting::query()
            ->pluck('value', 'key')
            ->toArray();

        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $dataServiceToggleKeys = [
            'data_service_enabled_mtn_awoof',
            'data_service_enabled_mtn_gifting',
            'data_service_enabled_mtn_sme',
            'data_service_enabled_mtn_cg',
            'data_service_enabled_mtn_cg_lite',
            'data_service_enabled_mtn_coupon',
            'data_service_enabled_mtncg',
            'data_service_enabled_airtel_sme',
            'data_service_enabled_airtel_cg',
            'data_service_enabled_airtel_gifting',
            'data_service_enabled_glo_data',
            'data_service_enabled_glo_sme',
            'data_service_enabled_etisalat_data',
        ];

        $referralServiceToggleKeys = [
            'referral_enabled_airtime',
            'referral_enabled_data',
            'referral_enabled_cable',
            'referral_enabled_electricity',
            'referral_enabled_exam',
            'referral_enabled_recharge_card',
            'referral_enabled_premium',
        ];

        $serviceMapKeys = [
            // Airtime
            'service_map_mtn',
            'service_map_airtel',
            'service_map_glo',
            'service_map_etisalat',
            'service_map_card_mtn',
            'service_map_card_airtel',
            'service_map_card_glo',
            'service_map_card_etisalat',

            // Data
            'service_map_mtn_gifting',
            'service_map_mtn_sme',
            'service_map_mtn_awoof',
            'service_map_mtn_cg',
            'service_map_mtn_cg_lite',
            'service_map_mtn_coupon',
            'service_map_mtncg',
            'service_map_airtel_sme',
            'service_map_airtel_cg',
            'service_map_airtel_gifting',
            'service_map_glo_data',
            'service_map_glo_sme',
            'service_map_etisalat_data',

            // Cable
            'service_map_dstv',
            'service_map_gotv',
            'service_map_startimes',

            // Electricity
            'service_map_abuja-electric',
            'service_map_eko-electric',
            'service_map_ibadan-electric',
            'service_map_ikeja-electric',
            'service_map_jos-electic',
            'service_map_kaduna-electric',
            'service_map_kano-electric',
            'service_map_portharcourt-electric',
            'service_map_aba-electric',
            'service_map_yola-electric',
            'service_map_benin-electric',
            'service_map_enugu-electric',
        ];

        $serviceMapRules = [];
        foreach ($serviceMapKeys as $key) {
            $serviceMapRules[$key] = ['nullable', 'string', 'max:120'];
        }
        $serviceMapRules['service_exam_waec'] = ['nullable', 'string', 'max:120'];
        $serviceMapRules['service_exam_neco'] = ['nullable', 'string', 'max:120'];
        $serviceMapRules['service_exam_nabteb'] = ['nullable', 'string', 'max:120'];
        $serviceMapRules['service_exam_jamb'] = ['nullable', 'string', 'max:120'];
        $serviceMapRules['service_map_canva'] = ['nullable', 'string', 'max:120'];

        // Add/remove keys here WITHOUT changing your UI structure
        $data = $request->validate(array_merge([
            'site_name'             => ['nullable', 'string', 'max:120'],
            'whatsapp_link'         => ['nullable', 'string', 'max:255'],
            'whatsapp_channel_link' => ['nullable', 'string', 'max:255'],
            'provider'              => ['nullable', 'string', 'in:gsubz,alt,mock'],

            'home_marquee_message'      => ['nullable', 'string', 'max:500'],
            'home_popup_message'        => ['nullable', 'string', 'max:5000'],
            'dashboard_marquee_message' => ['nullable', 'string', 'max:500'],
            'dashboard_popup_message'   => ['nullable', 'string', 'max:5000'],
            'fund_wallet_marquee_message' => ['nullable', 'string', 'max:500'],
            'marquee_speed_seconds' => ['nullable', 'numeric', 'min:5', 'max:120'],
            'maintenance_overlay_end_at' => ['nullable', 'date'],
            'maintenance_overlay_message' => ['nullable', 'string', 'max:500'],

            'services_airtime'     => ['nullable', 'string', 'max:4000'],
            'services_data'        => ['nullable', 'string', 'max:8000'],
            'services_cable'       => ['nullable', 'string', 'max:2000'],
            'services_electricity' => ['nullable', 'string', 'max:4000'],
            'services_education'   => ['nullable', 'string', 'max:4000'],
            'services_premium'     => ['nullable', 'string', 'max:4000'],

            'wallet_funding_fee' => ['nullable', 'numeric', 'min:0'],
            'data_plan_price_overrides' => ['nullable', 'string', 'max:20000'],

            'referral_default_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_airtime' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_data' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_cable' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_electricity' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_exam' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_recharge_card' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referral_percent_premium' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'markup_airtime'     => ['nullable', 'numeric', 'min:0'],
            'markup_data'        => ['nullable', 'numeric', 'min:0'],
            'markup_cable'       => ['nullable', 'numeric', 'min:0'],
            'markup_electricity' => ['nullable', 'numeric', 'min:0'],
            'markup_exam'        => ['nullable', 'numeric', 'min:0'],
            'markup_recharge_card' => ['nullable', 'numeric', 'min:0'],
            'markup_premium' => ['nullable', 'numeric', 'min:0'],
            'markup_bvn' => ['nullable', 'numeric', 'min:0'],
            'markup_nin_print' => ['nullable', 'numeric', 'min:0'],
            'markup_nin_validation' => ['nullable', 'numeric', 'min:0'],
            'airtime_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'price_exam_jamb'   => ['nullable', 'numeric', 'min:0'],
            'price_exam_waec'   => ['nullable', 'numeric', 'min:0'],
            'price_exam_neco'   => ['nullable', 'numeric', 'min:0'],
            'price_exam_nabteb' => ['nullable', 'numeric', 'min:0'],
            'price_exam_transaction_fee' => ['nullable', 'numeric', 'min:0'],
            'price_bvn_verify' => ['nullable', 'numeric', 'min:0'],
            'price_bvn_retrieve_phone' => ['nullable', 'numeric', 'min:0'],
            'price_bvn_retrieve_bms' => ['nullable', 'numeric', 'min:0'],
            'price_nin_verify' => ['nullable', 'numeric', 'min:0'],
            'price_nin_slip_long' => ['nullable', 'numeric', 'min:0'],
            'price_nin_slip_standard' => ['nullable', 'numeric', 'min:0'],
            'price_nin_slip_premium' => ['nullable', 'numeric', 'min:0'],
            'price_nin_slip_vnin' => ['nullable', 'numeric', 'min:0'],
            'price_nin_validation_no_record' => ['nullable', 'numeric', 'min:0'],
            'price_nin_validation_update_record' => ['nullable', 'numeric', 'min:0'],

            'provider_gsubz_base_url' => ['nullable', 'string', 'max:255'],
            'provider_gsubz_api_key' => ['nullable', 'string', 'max:255'],
            'provider_alt_base_url' => ['nullable', 'string', 'max:255'],
            'provider_alt_api_key' => ['nullable', 'string', 'max:255'],
            'service_map_profile_gsubz' => ['nullable', 'string', 'max:10000'],
            'service_map_profile_alt' => ['nullable', 'string', 'max:10000'],
            'service_map_profile_mock' => ['nullable', 'string', 'max:10000'],

            'nin_base_url' => ['nullable', 'string', 'max:255'],
            'nin_api_key' => ['nullable', 'string', 'max:255'],
            'nin_print_endpoint' => ['nullable', 'string', 'max:255'],
            'nin_reports_endpoint' => ['nullable', 'string', 'max:255'],
            'nin_validation_endpoint' => ['nullable', 'string', 'max:255'],
            'bvn_base_url' => ['nullable', 'string', 'max:255'],
            'bvn_api_key' => ['nullable', 'string', 'max:255'],
            'bvn_verify_endpoint' => ['nullable', 'string', 'max:255'],
            'bvn_retrieve_phone_endpoint' => ['nullable', 'string', 'max:255'],
            'bvn_retrieve_bms_endpoint' => ['nullable', 'string', 'max:255'],
            'bvn_print_endpoint' => ['nullable', 'string', 'max:255'],
            'app_download_url' => ['nullable', 'string', 'max:255'],
            'app_latest_version' => ['nullable', 'string', 'max:60'],

            'data_default_mtn_service' => [
                'nullable',
                'string',
                'in:mtn_awoof,mtn_gifting,mtn_sme',
            ],
            'data_default_airtel_service' => [
                'nullable',
                'string',
                'in:airtel_sme,airtel_cg,airtel_gifting',
            ],
            'data_default_glo_service' => [
                'nullable',
                'string',
                'in:glo_data,glo_sme',
            ],

            'recharge_card_networks' => ['nullable', 'string', 'max:2000'],
            'recharge_card_values' => ['nullable', 'string', 'max:2000'],

            'footer_phone' => ['nullable', 'string', 'max:120'],
            'footer_email' => ['nullable', 'string', 'max:120'],
            'footer_support_text' => ['nullable', 'string', 'max:200'],
            'social_facebook_url' => ['nullable', 'string', 'max:255'],
            'social_x_url' => ['nullable', 'string', 'max:255'],
            'social_instagram_url' => ['nullable', 'string', 'max:255'],
            'social_tiktok_url' => ['nullable', 'string', 'max:255'],
            'social_youtube_url' => ['nullable', 'string', 'max:255'],
            'social_whatsapp_url' => ['nullable', 'string', 'max:255'],

            'logo'            => ['nullable', 'image', 'max:2048'],
            'favicon'         => ['nullable', 'image', 'max:1024'],
        ], $serviceMapRules));

        foreach ($serviceMapKeys as $key) {
            if (array_key_exists($key, $data) && $this->looksLikePlanPrice($data[$key] ?? null)) {
                $data[$key] = '';
            }
        }

        foreach ($dataServiceToggleKeys as $toggleKey) {
            $data[$toggleKey] = $request->boolean($toggleKey) ? '1' : '0';
        }

        $data['referral_system_enabled'] = $request->boolean('referral_system_enabled') ? '1' : '0';
        foreach ($referralServiceToggleKeys as $toggleKey) {
            $data[$toggleKey] = $request->boolean($toggleKey) ? '1' : '0';
        }

        $data['home_popup_enabled'] = $request->boolean('home_popup_enabled') ? '1' : '0';
        $data['dashboard_popup_enabled'] = $request->boolean('dashboard_popup_enabled') ? '1' : '0';
        $data['maintenance_overlay_enabled'] = $request->boolean('maintenance_overlay_enabled') ? '1' : '0';

        // Backward compatibility for old keys
        $data['popup_enabled'] = $data['home_popup_enabled'];
        if (array_key_exists('home_popup_message', $data)) {
            $data['home_popup_message'] = sanitize_popup_message_html((string) $data['home_popup_message']);
            $data['popup_message'] = $data['home_popup_message'];
        }
        if (array_key_exists('dashboard_popup_message', $data)) {
            $data['dashboard_popup_message'] = sanitize_popup_message_html((string) $data['dashboard_popup_message']);
        }

        // handle uploads (store and save URL in settings)
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('site', 'public');
            $data['logo_url'] = '/storage/' . ltrim($path, '/');
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('site', 'public');
            $data['favicon_url'] = '/storage/' . ltrim($path, '/');
        }

        // remove file objects
        unset($data['logo'], $data['favicon']);

        foreach ($data as $key => $value) {
            // Skip nulls if you like; or allow saving empty string
            if ($value === null) continue;

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        settings_flush_cache();

        return back()->with('success', 'Settings updated successfully!');
    }

    private function looksLikePlanPrice(mixed $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^(?:\x{20A6}|N|NGN)?\s*\d+(?:[,.]\d+)?\s*(?:naira)?$/iu', $value);
    }
}
