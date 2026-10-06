<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Identity services that have no live provider connection yet.
 *
 * The customer fills the form exactly as they would for a wired service and is
 * charged at submission. An admin completes the request by hand and types or
 * uploads the result, which then appears on the customer's receipt. The JH Tech
 * catalogue (Delink, IPE Clearance, Agreement, Personalize, Modification) is
 * the source of the service list; prices and turnaround are admin settings so
 * they can be corrected without a deploy.
 */
class ManualFulfilmentService
{
    public const DEFAULT_TURNAROUND_HOURS = 48;

    /** Most useful first: what the job is worked from at the provider. */
    private const QUEUE_PRIORITY_FIELDS = [
        'nin',
        'bvn',
        'tracking_id',
        'delink_value',
        'bms_no',
        'ticket_id',
        'new_value',
        'phone',
        'validation_type',
        'retrieve_type',
        'ipe_type',
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
                'fields' => [
                    $this->field('tracking_id', 'Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num'], hint: 'The 15-character tracking ID printed on your NIN enrolment slip.'),
                    $this->field('ipe_type', 'Request type', type: 'select', options: ['new_enrollment' => 'New enrolment'], rules: ['required', 'in:new_enrollment']),
                    $this->field('nin', 'NIN', rules: ['nullable', 'digits:11'], hint: 'Optional, but it speeds up the search.'),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
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
                'fields' => [
                    $this->field('tracking_id', 'Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num']),
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('firstname', 'First name', rules: ['required', 'string', 'max:120']),
                    $this->field('lastname', 'Last name', rules: ['required', 'string', 'max:120']),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
                    $this->field('email', 'Email address', rules: ['nullable', 'email', 'max:160']),
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
                'fields' => [
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('tracking_id', 'Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num']),
                    $this->field('field_to_modify', 'Detail to correct', type: 'select', options: [
                        'firstname' => 'First name',
                        'lastname' => 'Last name',
                        'date_of_birth' => 'Date of birth',
                        'gender' => 'Gender',
                        'phone' => 'Phone number',
                        'address' => 'Address',
                        'photo' => 'Photograph',
                        'other' => 'Other',
                    ], rules: ['required', 'string', 'max:60']),
                    $this->field('current_value', 'What it currently says', rules: ['nullable', 'string', 'max:255']),
                    $this->field('new_value', 'What it should say', rules: ['required', 'string', 'max:255']),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
                    $this->field('email', 'Email address', rules: ['nullable', 'email', 'max:160']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_delink' => [
                'slug' => 'nin_delink',
                'title' => 'Self Service Delink',
                'icon' => '⛓',
                'summary' => 'Remove a phone number or bank account that is still linked to your NIN.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'fields' => [
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('tracking_id', 'Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num']),
                    $this->field('delink_target', 'What should be delinked', type: 'select', options: [
                        'phone' => 'Phone number',
                        'bank_account' => 'Bank account',
                        'email' => 'Email address',
                        'other' => 'Other',
                    ], rules: ['required', 'string', 'max:40']),
                    $this->field('delink_value', 'The exact value to remove', rules: ['required', 'string', 'max:255']),
                    $this->field('phone', 'Phone number to reach you on', rules: ['required', 'string', 'max:20']),
                    $this->field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_agreement' => [
                'slug' => 'nin_agreement',
                'title' => 'NIN Agreement',
                'icon' => '📄',
                'summary' => 'Request the agreement form tied to your enrolment.',
                'group' => 'nin',
                'turnaround_default' => 48,
                'fields' => [
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('tracking_id', 'Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num']),
                    $this->field('state', 'State of enrolment', rules: ['required', 'string', 'max:80']),
                    $this->field('lga', 'LGA of enrolment', rules: ['nullable', 'string', 'max:80']),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
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
                'fields' => [
                    $this->field('bvn', 'BVN', rules: ['required', 'digits:11']),
                    $this->field('firstname', 'First name on the BVN', rules: ['nullable', 'string', 'max:120']),
                    $this->field('lastname', 'Last name on the BVN', rules: ['nullable', 'string', 'max:120']),
                    $this->field('phone', 'Phone number linked to the BVN', rules: ['nullable', 'string', 'max:20']),
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
                'fields' => [
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('tracking_id', 'Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num']),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
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
                'fields' => [
                    $this->field('validation_type', 'Validation type', type: 'select', options: [
                        'no_record' => 'No record',
                        'update_record' => 'Update record',
                    ], rules: ['required', 'in:no_record,update_record']),
                    $this->field('nin', 'NIN', rules: ['required', 'digits:11']),
                    $this->field('phone', 'Phone number', rules: ['required', 'string', 'max:20']),
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
                'fields' => [
                    $this->field('retrieve_type', 'Retrieve by', type: 'select', options: [
                        'phone' => 'Phone number',
                        'bms' => 'BMS ticket',
                    ], rules: ['required', 'in:phone,bms']),
                    $this->field('phone', 'Phone number', rules: ['nullable', 'string', 'max:20']),
                    $this->field('bms_no', 'BMS number', rules: ['nullable', 'string', 'max:100']),
                    $this->field('ticket_id', 'Ticket ID', rules: ['nullable', 'string', 'max:120']),
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

            $field = $fields[$name] ?? ['label' => Str::headline($name), 'options' => []];

            $look[] = [
                'label' => $field['label'],
                'value' => (string) ($field['options'][$value] ?? $value),
            ];

            if (count($look) >= $limit) {
                break;
            }
        }

        return $look;
    }

    public function priceKey(string $slug): string
    {
        return 'price_manual_'.$slug;
    }

    public function turnaroundKey(string $slug): string
    {
        return 'turnaround_manual_'.$slug;
    }

    public function priceNaira(string $slug): float
    {
        return identity_price($this->priceKey($slug));
    }

    /** What the form shows before the owner has set a price of their own. */
    public function defaultPrice(string $slug): float
    {
        return identity_reference_price($this->priceKey($slug));
    }

    /** What jhtechltd.com charges for the same job, or null when unpublished. */
    public function providerCost(string $slug): ?float
    {
        return identity_cost($this->priceKey($slug));
    }

    public function markupNaira(string $slug): float
    {
        return (float) setting('markup_manual_'.$slug, 0);
    }

    public function totalNaira(string $slug): float
    {
        return $this->priceNaira($slug) + $this->markupNaira($slug);
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
            $keys[] = $this->priceKey($slug);
            $keys[] = 'markup_manual_'.$slug;
            $keys[] = $this->turnaroundKey($slug);
        }

        return $keys;
    }

    /**
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
    ): array {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'rules' => $rules,
            'options' => $options,
            'hint' => $hint,
            'required' => in_array('required', $rules, true),
        ];
    }
}
