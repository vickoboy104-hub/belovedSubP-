<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            // =========================
            // AIRTIME (serviceID = network)
            // =========================
            ['name' => 'MTN Airtime',     'slug' => 'mtn'],
            ['name' => 'Airtel Airtime',  'slug' => 'airtel'],
            ['name' => 'GLO Airtime',     'slug' => 'glo'],
            ['name' => '9mobile Airtime', 'slug' => 'etisalat'],

            // =========================
            // DATA (serviceID = service_id)
            // =========================
            ['name' => 'MTN Data SME',        'slug' => 'mtn_sme'],
            ['name' => 'MTN Data Gifting',    'slug' => 'mtn_gifting'],
            ['name' => 'MTN Data Corporate',  'slug' => 'mtn_cg'],
            ['name' => 'MTN Awoof Data (Cheap)', 'slug' => 'mtn_awoof'],
            ['name' => 'Airtel Data (SME)',   'slug' => 'airtel_sme'],
            ['name' => 'Airtel Data (CG)',    'slug' => 'airtel_cg'],
            ['name' => 'Airtel Data (Gifting)', 'slug' => 'airtel_gifting'],
            ['name' => 'GLO Data',            'slug' => 'glo_data'],
            ['name' => 'GLO Data (SME)',      'slug' => 'glo_sme'],
            ['name' => '9mobile Data',        'slug' => 'etisalat_data'],
            ['name' => 'Smile Data',          'slug' => 'smile'],
            ['name' => 'Spectranet Data',     'slug' => 'spectranet'],

            // =========================
            // CABLE TV (serviceID = service_id)
            // =========================
            ['name' => 'GOtv Subscription',      'slug' => 'gotv'],
            ['name' => 'DStv Subscription',      'slug' => 'dstv'],
            ['name' => 'Startimes Subscription', 'slug' => 'startimes'],

            // =========================
            // EXAM PINS (serviceID = pin_code)
            // =========================
            ['name' => 'JAMB PIN (UTME & Direct Entry)', 'slug' => 'jamb'],
            ['name' => 'WAEC Result Checker PIN',   'slug' => 'waec'],
            ['name' => 'NECO Result Checker PIN',   'slug' => 'neco'],
            ['name' => 'NABTEB Result Checker PIN', 'slug' => 'nabteb'],

            // =========================
            // SOCIAL / PREMIUM
            // =========================
            ['name' => 'Canva Pro', 'slug' => 'canva'],

            // =========================
            // ELECTRICITY (serviceID = service_id)
            // (Codes can vary by provider; we can adjust to match your GSUBZ list if needed)
            // =========================
            ['name' => 'Ikeja Electric (IKEDC)', 'slug' => 'ikeja-electric'],
            ['name' => 'Eko Electric (EKEDC)',   'slug' => 'eko-electric'],
            ['name' => 'Abuja Electric (AEDC)',  'slug' => 'abuja-electric'],
            ['name' => 'Kano Electric (KEDCO)',  'slug' => 'kano-electric'],
            ['name' => 'Kaduna Electric (KAEDCO)','slug' => 'kaduna-electric'],
            ['name' => 'Port Harcourt Electric (PHED)', 'slug' => 'phed-electric'],
            ['name' => 'Jos Electric (JED)',     'slug' => 'jos-electric'],
            ['name' => 'Ibadan Electric (IBEDC)','slug' => 'ibadan-electric'],
            ['name' => 'Benin Electric (BEDC)',  'slug' => 'benin-electric'],
            ['name' => 'Enugu Electric (EEDC)',  'slug' => 'enugu-electric'],
            ['name' => 'Yola Electric (YEDC)',   'slug' => 'yola-electric'],
        ];

        foreach ($services as $s) {
            Service::updateOrCreate(
                ['slug' => $s['slug']],
                ['name' => $s['name']]
            );
        }
    }
}
