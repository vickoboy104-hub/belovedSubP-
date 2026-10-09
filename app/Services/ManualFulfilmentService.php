<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Identity services a person completes instead of an API.
 *
 * The customer fills the form exactly as they would for a wired service and is
 * charged at submission. An admin completes the request by hand and types or
 * uploads the result, which then appears on the customer's receipt. The JH Tech
 * catalogue (Delink, IPE Clearance, Agreement, Personalize, Modification) is the
 * source of both the service list and the wording on each screen — labels,
 * dropdown options, examples and warnings are read off the provider's own pages,
 * while every price and turnaround stays an admin setting so a number can be
 * corrected without a deploy. Verification appears here too when the owner
 * switches it off the ConfirmIdent endpoints.
 *
 * A service asks for what the provider works from and nothing more: the
 * customer's own phone and email are already on their account and on the admin's
 * detail page, so collecting them again only makes the form longer.
 */
class ManualFulfilmentService
{
    public const DEFAULT_TURNAROUND_HOURS = 48;

    /** The provider's own example, so a customer sees the shape they must type. */
    private const NIN_PLACEHOLDER = 'Enter NIN e.g 74227342856';

    private const BVN_PLACEHOLDER = 'Enter BVN';

    /** Most useful first: what the job is worked from at the provider. */
    private const QUEUE_PRIORITY_FIELDS = [
        'nin',
        'bvn',
        'tracking_id',
        'bms_no',
        'ticket_id',
        'new_value',
        'phone',
        'verification_type',
        'validation_type',
        'retrieve_type',
        'ipe_type',
        'slip_type',
        'delink_target',
        'field_to_modify',
        'state',
    ];

    /** @return array<string, array<string, mixed>> */
    public function catalogue(): array
    {
        return [
            'ipe_clearance' => [
                'slug' => 'ipe_clearance',
                'title' => 'IPE Clearance',
                'icon' => '🔎',
                'summary' => 'Clear an identity processing exception using the tracking number on your enrolment slip.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'cta' => 'Submit Tracking',
                'fields' => [
                    $this->field('ipe_type', 'Select IPEs Category', type: 'select', options: [
                        'new_enrollment' => 'New Enrollment for ID Retrieval',
                        'inprocessing_error' => 'Inprocessing Error',
                        'still_being_process' => 'Enrollment is Still Being Process',
                    ], rules: ['required', 'in:new_enrollment,inprocessing_error,still_being_process'], column: 'Type'),
                    $this->field('tracking_id', 'Enter Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num'], hint: 'The 15-character tracking ID printed on your NIN enrolment slip, e.g. BTX947E60001020.', column: 'Tracking ID'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_personalization' => [
                'slug' => 'nin_personalization',
                'title' => 'NIN Personalization',
                'icon' => '▣',
                'summary' => 'Request personalization of a tracking number and follow the processing status.',
                'group' => 'nin',
                'turnaround_default' => 48,
                // The provider deletes the personalized slip a week after it is
                // issued, which is a fact the customer can only act on early.
                'notices' => [
                    'The personalized slip is removed from our server one week after it is issued, so save or print it before then.',
                ],
                // Their page prices the two answers separately: N250 for the slip
                // and N150 for the NIN number alone, so the cheaper answer is not
                // sold at the slip rate.
                'tiers' => [
                    'field' => 'category',
                    'map' => [
                        'get_nin_slip' => 'price_manual_nin_personalization',
                        'get_nin_only' => 'price_manual_nin_personalization_nin_only',
                    ],
                ],
                'fields' => [
                    $this->field('tracking_id', 'Enter Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num'], hint: 'The 15-character tracking ID printed on your NIN enrolment slip.', column: 'Tracking'),
                    $this->field('category', 'Select Category', type: 'select', options: [
                        'get_nin_slip' => 'TO GET NIN SLIP',
                        'get_nin_only' => 'TO GET NIN NUMBER ONLY',
                    ], rules: ['required', 'in:get_nin_slip,get_nin_only'], column: 'Type'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_modification' => [
                'slug' => 'nin_modification',
                'title' => 'NIN Modification',
                'icon' => '✎',
                'summary' => 'Submit a correction to the details held against a NIN record.',
                'group' => 'nin',
                'turnaround_default' => 72,
                // The ten corrections below are not one job: their own form ships
                // a hidden price per correction set, and a date of birth costs
                // more than six times what a name does. Each option therefore
                // carries its own rate instead of everything sharing one number.
                'tiers' => [
                    'field' => 'field_to_modify',
                    'map' => [
                        'name' => 'price_manual_nin_modification',
                        'phone' => 'price_manual_nin_modification',
                        'email' => 'price_manual_nin_modification',
                        'address' => 'price_manual_nin_modification',
                        'name_phone' => 'price_manual_nin_modification_name_pair',
                        'name_email' => 'price_manual_nin_modification_name_pair',
                        'name_address' => 'price_manual_nin_modification_name_pair',
                        'name_dob' => 'price_manual_nin_modification_dob_pair',
                        'dob_phone' => 'price_manual_nin_modification_dob_pair',
                        'dob' => 'price_manual_nin_modification_dob',
                    ],
                    'labels' => [
                        'price_manual_nin_modification' => 'One detail',
                        'price_manual_nin_modification_name_pair' => 'Name with phone, email or address',
                        'price_manual_nin_modification_dob_pair' => 'Date of birth with name or phone',
                        'price_manual_nin_modification_dob' => 'Date of birth alone',
                    ],
                ],
                'fields' => [
                    $this->field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    $this->field('field_to_modify', 'What needs correcting', type: 'select', options: [
                        'name' => 'Change of Name',
                        'phone' => 'Change of Phone Number',
                        'address' => 'Change of Address',
                        'email' => 'Change of Email',
                        'dob' => 'Change of Date of Birth',
                        'name_phone' => 'Name and Phone Number',
                        'name_dob' => 'Name and Date of Birth',
                        'name_email' => 'Name and Email',
                        'name_address' => 'Name and Address',
                        'dob_phone' => 'Date of Birth and Phone Number',
                    ], rules: ['required', 'in:name,phone,address,email,dob,name_phone,name_dob,name_email,name_address,dob_phone'], column: 'Mod Type'),
                    $this->field('new_value', 'What it should say', rules: ['required', 'string', 'max:255'], column: 'New value'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_delink' => [
                'slug' => 'nin_delink',
                'title' => 'Self Service Delink',
                'icon' => '⛓',
                'summary' => 'Unlink a self service account from your NIN, or recover the email on it.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'fields' => [
                    $this->field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    $this->field('delink_target', 'What you want done', type: 'select', options: [
                        'delink' => 'Delink my self service account',
                        'retrieve_email' => 'Retrieve the email on my self service account',
                    ], rules: ['required', 'in:delink,retrieve_email'], column: 'Type'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            // JH Tech's own /agreement screen turned out to be their non-withdrawal
            // policy for resellers, not a job a customer can order. Nothing on the
            // provider side matches this, so it is off the hub until the owner
            // decides whether to sell it under our own terms or drop it.
            'nin_agreement' => [
                'slug' => 'nin_agreement',
                'title' => 'NIN Agreement',
                'icon' => '📄',
                'summary' => 'Request the agreement form tied to your enrolment.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'hidden_from_hub' => true,
                'fields' => [
                    $this->field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    $this->field('tracking_id', 'Enter Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num'], column: 'Tracking ID'),
                    $this->field('state', 'State of enrolment', rules: ['required', 'string', 'max:80']),
                    $this->field('lga', 'LGA of enrolment', rules: ['nullable', 'string', 'max:80']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'bvn_print' => [
                'slug' => 'bvn_print',
                'title' => 'Print BVN Slip',
                'icon' => '🖨',
                'summary' => 'Get a printable BVN slip delivered to your receipt page.',
                'group' => 'bvn',
                'turnaround_default' => 24,
                'cta' => 'Print BVN Slip Now',
                'cost_text' => 'It costs ₦{{price}} per slip',
                'fields' => [
                    $this->field('bvn', 'Enter the BVN Number', rules: ['required', 'digits:11'], placeholder: self::BVN_PLACEHOLDER, hint: 'The 11-digit Bank Verification Number.', column: 'BVN'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            // The three entries below are reached from the wired NIN and BVN pages
            // when a provider endpoint has no credentials yet. They stay off the
            // service hub because that page already links the wired form.
            'nin_slip_print' => [
                'slug' => 'nin_slip_print',
                'title' => 'Print NIN Slip',
                'icon' => '🖨',
                'summary' => 'Get the NIN slip printed and delivered to your receipt page.',
                'group' => 'nin',
                'turnaround_default' => 24,
                'hidden_from_hub' => true,
                // The provider destroys the printed slip a day after issuing it,
                // so a customer who means to keep it has to keep it early.
                'notices' => [
                    'A printed slip is removed from our server 24 hours after it is issued, so save it as soon as it appears on your receipt.',
                ],
                // A printed slip is the same job whether a machine or a person
                // prints it, so each slip price below points at the wired rate
                // instead of inventing a second number to keep in sync.
                'tiers' => [
                    'field' => 'slip_type',
                    'map' => [
                        'long_slip' => 'price_nin_slip_long',
                        'standard_slip' => 'price_nin_slip_standard',
                        'premium_slip' => 'price_nin_slip_premium',
                        'vnin_slip' => 'price_nin_slip_vnin',
                    ],
                ],
                'fields' => [
                    $this->field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, hint: 'The 11-digit National Identification Number.', column: 'NIN'),
                    // The four slips are four different jobs on their counter, each
                    // with its own rate, so the queue charges the one picked.
                    $this->field('slip_type', 'Choose Slip Type', type: 'select', options: [
                        'long_slip' => 'NIN Slip (Long Slip)',
                        'standard_slip' => 'Standard NIN Slip',
                        'premium_slip' => 'Premium NIN Slip',
                        'vnin_slip' => 'Vnin NIN Slip Sample',
                    ], rules: ['required', 'in:long_slip,standard_slip,premium_slip,vnin_slip'], column: 'Slip Type'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_validation' => [
                'slug' => 'nin_validation',
                'title' => 'NIN Validation',
                'icon' => '✓',
                'summary' => 'Submit a no record or update record validation and receive the outcome.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'hidden_from_hub' => true,
                'cta' => 'Submit NIN',
                // Our refund promise already covers the first half of the
                // provider's sentence; only the no-cancellation half is news.
                'notices' => [
                    'Once the request has been sent it cannot be cancelled.',
                ],
                'tiers' => [
                    'field' => 'validation_type',
                    'map' => [
                        'no_record' => 'price_nin_validation_no_record',
                        'update_record' => 'price_nin_validation_update_record',
                    ],
                ],
                'fields' => [
                    // 'Update Record' asks the provider to match a record that has
                    // already been corrected, which is a dearer lookup than finding
                    // nothing at all, so the two categories keep separate rates.
                    $this->field('validation_type', 'Select Validation Category', type: 'select', options: [
                        'no_record' => 'No Record Found',
                        'update_record' => 'Update Record(Modification Validation Name/Phone/Address except DOB)',
                    ], rules: ['required', 'in:no_record,update_record'], column: 'Type'),
                    $this->field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'bvn_retrieve' => [
                'slug' => 'bvn_retrieve',
                'title' => 'BVN Retrieval',
                'icon' => '🔎',
                'summary' => 'Retrieve the BVN linked to your phone number or BMS ticket.',
                'group' => 'bvn',
                'turnaround_default' => 48,
                'hidden_from_hub' => true,
                'tiers' => [
                    'field' => 'retrieve_type',
                    'map' => [
                        'phone' => 'price_bvn_retrieve_phone',
                        'bms' => 'price_bvn_retrieve_bms',
                    ],
                ],
                'fields' => [
                    $this->field('retrieve_type', 'Choose Category', type: 'select', options: [
                        'phone' => 'Using Phone Number',
                        'bms' => 'Using BMS Ticket',
                    ], rules: ['required', 'in:phone,bms'], column: 'Phone No / BMS'),
                    $this->field('phone', 'Enter Phone Number', rules: ['nullable', 'string', 'max:20']),
                    $this->field('bms_no', 'Enter BMS Number', rules: ['nullable', 'string', 'max:100']),
                    $this->field('ticket_id', 'Enter Ticket ID', rules: ['nullable', 'string', 'max:120']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            // Verification can also be run by a person when the provider is down or
            // the owner has switched the service to manual. These entries carry the
            // queue and the turnaround only: the price and the form stay on the
            // wired NIN and BVN pages, which is where customers meet them.
            'nin_verify' => [
                'slug' => 'nin_verify',
                'title' => 'NIN Verification',
                'icon' => '✓',
                'summary' => 'Confirm the details held against a NIN record.',
                'group' => 'nin',
                'turnaround_default' => 24,
                'hidden_from_hub' => true,
                'wired_only' => true,
                'price_key' => 'price_nin_verify',
                // The provider asks for the record three different ways and each is
                // a different amount of work to answer, so the admin can price
                // phone and demographic searches away from a plain NIN lookup.
                'tiers' => [
                    'field' => 'verification_type',
                    'map' => [
                        'by_nin' => 'price_nin_verify',
                        'by_phone' => 'price_nin_verify_by_phone',
                        'by_demo' => 'price_nin_verify_by_demo',
                    ],
                ],
                'fields' => [
                    $this->field('verification_type', 'Verification Type', type: 'select', options: [
                        'by_nin' => 'By NIN',
                        'by_phone' => 'By Phone No',
                        'by_demo' => 'By Demographic Info',
                    ], rules: ['required', 'in:by_nin,by_phone,by_demo'], column: 'Verified by'),
                    $this->field('nin', 'Enter the NIN Number', rules: ['nullable', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    $this->field('phone', 'Enter Phone Number', rules: ['nullable', 'string', 'max:20'], column: 'Phone'),
                    $this->field('firstname', 'First name', rules: ['nullable', 'string', 'max:120']),
                    $this->field('lastname', 'Last name', rules: ['nullable', 'string', 'max:120']),
                    $this->field('dob', 'Date of birth', rules: ['nullable', 'string', 'max:20']),
                    $this->field('gender', 'Gender', rules: ['nullable', 'string', 'max:20']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'bvn_verify' => [
                'slug' => 'bvn_verify',
                'title' => 'BVN Verification',
                'icon' => '✓',
                'summary' => 'Confirm the details held against a BVN.',
                'group' => 'bvn',
                'turnaround_default' => 24,
                'hidden_from_hub' => true,
                'wired_only' => true,
                'price_key' => 'price_bvn_verify',
                'fields' => [
                    $this->field('bvn', 'BVN', rules: ['required', 'digits:11']),
                    $this->field('firstname', 'First name', rules: ['nullable', 'string', 'max:120']),
                    $this->field('lastname', 'Last name', rules: ['nullable', 'string', 'max:120']),
                    $this->field('phone', 'Phone number', rules: ['nullable', 'string', 'max:20']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],
        ];
    }

    public function find(string $slug): ?array
    {
        return $this->catalogue()[$slug] ?? null;
    }

    public function queueQuery(): Builder
    {
        return Order::query()->where('meta->manual_queue', true);
    }

    public function waitingQuery(): Builder
    {
        return $this->queueQuery()->where('status', 'pending');
    }

    public function waitingCount(): int
    {
        return $this->waitingQuery()->count();
    }

    /**
     * The identifiers an admin needs to start the job, without opening the request.
     *
     * @param  array<string, mixed>  $submitted
     * @return list<array{label: string, value: string}>
     */
    public function quickLook(string $slug, array $submitted, int $limit = 3): array
    {
        $fields = [];
        foreach ($this->find($slug)['fields'] ?? [] as $field) {
            $fields[$field['name']] = $field;
        }

        $look = [];
        foreach (self::QUEUE_PRIORITY_FIELDS as $name) {
            $value = trim((string) ($submitted[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            $field = $fields[$name] ?? ['column' => Str::headline($name), 'options' => []];

            $look[] = [
                'label' => $field['column'],
                'value' => (string) ($field['options'][$value] ?? $value),
            ];

            if (count($look) >= $limit) {
                break;
            }
        }

        return $look;
    }

    /**
     * The rate the customer is charged for the job they actually picked.
     *
     * Some services are one job with one price; others are several different
     * jobs under a single name, like the ten NIN corrections, where the provider
     * itself ships a different rate per correction. Without a selection to work
     * from the service-level key answers, which is the number the hub and the
     * settings page show as that service's price.
     *
     * Verification is priced the same whether a robot or a person runs it, so
     * those catalogue entries point back at the wired price keys instead of
     * inventing a second price the owner would have to keep in sync.
     *
     * @param  array<string, mixed>  $submitted
     */
    public function priceKey(string $slug, array $submitted = []): string
    {
        $service = $this->find($slug) ?? [];
        $base = $this->servicePriceKey($slug);

        $map = $service['tiers']['map'] ?? [];
        $option = trim((string) ($submitted[$service['tiers']['field'] ?? ''] ?? ''));

        return $option !== '' && isset($map[$option]) ? $map[$option] : $base;
    }

    /** The key that holds this service's own price, tier or no tier. */
    public function servicePriceKey(string $slug): string
    {
        return $this->find($slug)['price_key'] ?? 'price_manual_'.$slug;
    }

    /**
     * Every rate this service can charge, with the options that lead to it.
     *
     * The settings page renders one input per entry, so a service that gains a
     * tier gains its own editable price without that page being touched.
     *
     * @return list<array{key: string, label: string, options: list<string>}>
     */
    public function priceTiers(string $slug): array
    {
        $service = $this->find($slug) ?? [];
        $map = $service['tiers']['map'] ?? [];
        if ($map === []) {
            return [];
        }

        $names = $this->optionsFor($slug, (string) $service['tiers']['field']);
        $labels = $service['tiers']['labels'] ?? [];

        $grouped = [];
        foreach ($map as $option => $key) {
            $grouped[$key][] = $names[$option] ?? $option;
        }

        $tiers = [];
        foreach ($grouped as $key => $options) {
            $tiers[] = [
                'key' => $key,
                'label' => $labels[$key] ?? (count($options) === 1
                    ? $options[0]
                    : count($options).' options'),
                'options' => $options,
            ];
        }

        return $tiers;
    }

    /**
     * What each selectable option costs, so the form can name the price the
     * moment the customer picks one, the way the provider's own page does.
     *
     * @return array<string, float>
     */
    public function tierPrices(string $slug): array
    {
        $service = $this->find($slug) ?? [];
        $map = $service['tiers']['map'] ?? [];

        $prices = [];
        foreach ($map as $option => $key) {
            $prices[$option] = identity_tier_price($key, $this->servicePriceKey($slug))
                + $this->markupNaira($slug);
        }

        return $prices;
    }

    /**
     * The price line for a service that has more than one rate: a single number
     * when every tier is priced alike, otherwise the range the customer picks
     * between, so the hub never advertises a date-of-birth correction at the
     * price of a name change.
     */
    public function priceRangeLabel(string $slug): string
    {
        $prices = array_map(
            fn (float $naira): int => (int) round($naira * 100),
            $this->tierPrices($slug),
        );

        if ($prices === []) {
            return '₦'.number_format($this->totalNaira($slug), 0);
        }

        $lowest = min($prices);
        $highest = max($prices);

        if ($lowest === $highest) {
            return '₦'.number_format($lowest / 100, 0);
        }

        return '₦'.number_format($lowest / 100, 0).' – ₦'.number_format($highest / 100, 0);
    }

    /** @return array<string, string> */
    private function optionsFor(string $slug, string $field): array
    {
        foreach ($this->find($slug)['fields'] ?? [] as $definition) {
            if ($definition['name'] === $field) {
                return $definition['options'];
            }
        }

        return [];
    }

    public function turnaroundKey(string $slug): string
    {
        return 'turnaround_manual_'.$slug;
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    public function priceNaira(string $slug, array $submitted = []): float
    {
        return identity_tier_price($this->priceKey($slug, $submitted), $this->servicePriceKey($slug));
    }

    /** What the form shows before the owner has set a price of their own. */
    public function defaultPrice(string $slug, array $submitted = []): float
    {
        return identity_reference_price($this->priceKey($slug, $submitted));
    }

    /** What the provider charges for the same job, or null when unpublished. */
    public function providerCost(string $slug, array $submitted = []): ?float
    {
        return identity_cost($this->priceKey($slug, $submitted));
    }

    public function markupNaira(string $slug): float
    {
        return (float) setting('markup_manual_'.$slug, 0);
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    public function totalNaira(string $slug, array $submitted = []): float
    {
        return $this->priceNaira($slug, $submitted) + $this->markupNaira($slug);
    }

    public function turnaroundHours(string $slug): int
    {
        $service = $this->find($slug);
        $default = (int) ($service['turnaround_default'] ?? self::DEFAULT_TURNAROUND_HOURS);
        $stored = setting($this->turnaroundKey($slug));

        $hours = $stored === null || $stored === '' ? $default : (int) $stored;

        return $hours > 0 ? $hours : self::DEFAULT_TURNAROUND_HOURS;
    }

    public function expectedBy(string $slug): Carbon
    {
        return now()->addHours($this->turnaroundHours($slug));
    }

    /** What the customer is promised before they pay. */
    public function turnaroundLabel(string $slug): string
    {
        $hours = $this->turnaroundHours($slug);

        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? 'About 24 hours' : 'About '.$days.' days';
        }

        return 'About '.$hours.' hours';
    }

    /** @return array<string, mixed> */
    public function validationRules(string $slug): array
    {
        $service = $this->find($slug);
        if (!$service) {
            return [];
        }

        $rules = [];
        foreach ($service['fields'] as $field) {
            $rules[$field['name']] = $field['rules'];
        }

        return $rules;
    }

    /**
     * Every settings key the admin page and the settings controller must know
     * about, so a new catalogue entry becomes editable without touching either.
     *
     * @return list<string>
     */
    public function settingKeys(): array
    {
        $keys = [];
        foreach (array_keys($this->catalogue()) as $slug) {
            $keys[] = $this->servicePriceKey($slug);
            foreach ($this->priceTiers($slug) as $tier) {
                $keys[] = $tier['key'];
            }
            if (!$this->sharesWiredPrice($slug)) {
                $keys[] = 'markup_manual_'.$slug;
            }
            $keys[] = $this->turnaroundKey($slug);
        }

        return array_values(array_unique($keys));
    }

    /** True when the price lives with the wired version of the same job. */
    public function sharesWiredPrice(string $slug): bool
    {
        return isset($this->find($slug)['price_key']);
    }

    /**
     * True for jobs the customer only reaches from their wired service page, so
     * the generic manual form must not offer a second, differently validated way
     * into the same queue.
     */
    public function wiredOnly(string $slug): bool
    {
        return !empty($this->find($slug)['wired_only']);
    }

    /**
     * A label is written the way the provider writes it, which is often a full
     * instruction ("Enter the NIN Number"). The history table and the admin queue
     * need the short name of the same thing instead, so a field carries both.
     *
     * @param  list<string>  $rules
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $label,
        string $type = 'text',
        array $rules = [],
        array $options = [],
        ?string $hint = null,
        ?string $placeholder = null,
        ?string $column = null,
    ): array {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'rules' => $rules,
            'options' => $options,
            'hint' => $hint,
            'placeholder' => $placeholder,
            'column' => $column ?? $label,
            'required' => in_array('required', $rules, true),
        ];
    }
}
