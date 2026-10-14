<?php

namespace App\Services;

use App\Models\Order;
use App\Support\ServiceForm\Schema;
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

    /**
     * What a customer accepts before a form that touches their identity record
     * appears at all. Written for this platform, in our own words, but each line
     * has to stay true to how the queue actually behaves: the fee is taken at
     * submission, a job we cannot finish is credited back to the wallet, and the
     * wallet is spendable here rather than withdrawable.
     *
     * @var list<string>
     */
    private const CONSENT_IDENTITY = [
        'I authorise BelovedSubP and the agents it works with to use the details I submit here to carry out this request on my behalf.',
        'BelovedSubP is not NIMC, NIBSS, a bank or any government registry. Anything it returns is prepared on my behalf, not issued directly by any of them.',
        'The fee is taken from my wallet when I submit, and it pays for the work attempted as well as the work completed.',
        'If the request cannot be completed, the amount is returned to my wallet, where it stays spendable on services here.',
        'I should not send the same request through another channel while this one is still running; doing so can spoil both and the fee is still spent.',
        'If I am submitting for somebody else, the owner of that record has authorised me to do it.',
    ];

    /**
     * Extra terms for the services where the upstream record owner can change the
     * rules mid-flight, and where the customer's own expectations about speed are
     * the usual source of complaints.
     *
     * @var list<string>
     */
    private const CONSENT_MODIFICATION = [
        'NIMC would rather I make this change myself on its own portal. I am asking BelovedSubP to do it for me, and if NIMC later limits changes to the record owner only, this request may not be possible.',
        'NIMC updates its own record first; banks and phone networks can take considerably longer to reflect the change. If I need the new detail for banking immediately, I should not submit this request today.',
        'Any account credential handed to me as part of this job must be used as given; changing it myself before the record has settled can cost me the work.',
    ];

    /** Most useful first: what the job is worked from at the provider. */
    private const QUEUE_PRIORITY_FIELDS = [
        'nin',
        // Which detail is being corrected decides every question an agent then
        // reads, so it belongs above the answers it selects.
        'field_to_modify',
        'bvn',
        'tracking_id',
        'bms_no',
        'ticket_id',
        'fname',
        'phone',
        'verification_type',
        'validation_type',
        'retrieve_type',
        'ipe_type',
        'slip_type',
        'delink_target',
        'state',
    ];

    /**
     * The catalogue as it was written in code, which is what a service inherits
     * until the owner saves their own version of it in Admin.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function legacyCatalogue(): array
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
                    self::field('ipe_type', 'Select IPEs Category', type: 'select', options: [
                        'new_enrollment' => 'New Enrollment for ID Retrieval',
                        'inprocessing_error' => 'Inprocessing Error',
                        'still_being_process' => 'Enrollment is Still Being Process',
                    ], rules: ['required', 'in:new_enrollment,inprocessing_error,still_being_process'], column: 'Type'),
                    self::field('tracking_id', 'Enter Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num'], hint: 'The 15-character tracking ID printed on your NIN enrolment slip, e.g. BTX947E60001020.', column: 'Tracking ID'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('tracking_id', 'Enter Tracking ID', rules: ['required', 'string', 'size:15', 'alpha_num'], hint: 'The 15-character tracking ID printed on your NIN enrolment slip.', column: 'Tracking'),
                    self::field('category', 'Select Category', type: 'select', options: [
                        'get_nin_slip' => 'TO GET NIN SLIP',
                        'get_nin_only' => 'TO GET NIN NUMBER ONLY',
                    ], rules: ['required', 'in:get_nin_slip,get_nin_only'], column: 'Type'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],

            'nin_modification' => [
                'slug' => 'nin_modification',
                'title' => 'NIN Modification',
                'icon' => '✎',
                'summary' => 'Submit a correction to the details held against a NIN record.',
                'group' => 'nin',
                'turnaround_default' => 72,
                'consent' => [
                    'title' => 'Consent and authorisation',
                    'intro' => 'A modification rewrites the details held against your NIN. Read each line - the form stays hidden until you accept them.',
                    'clauses' => [...self::CONSENT_IDENTITY, ...self::CONSENT_MODIFICATION],
                ],
                // Their page opens on the one rule that costs a customer their
                // money, so it is said before the fields rather than after them.
                'notices' => [
                    'You cannot submit the same modification request to another platform while we process it. Doing so forfeits the payment, because the correction is paid for either way.',
                ],
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
                    self::field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    // The correction is made through the NIMC self-service account
                    // that is linked to the NIN, so the account has to be reached
                    // before the record can be rewritten. Their form offers both
                    // routes and asks for an address only when the customer picks
                    // the first one.
                    self::field('email_choice', 'Which email should the self-service account use', type: 'select', options: [
                        'link' => 'Link my own email (Gmail or Outlook)',
                        'auto' => 'Auto-generate an email for me',
                    ], rules: ['required', 'in:link,auto'], column: 'Email'),
                    self::field('link_email', 'Enter the email to link', rules: ['required', 'email', 'max:255'], placeholder: 'you@example.com', showIf: ['field' => 'email_choice', 'any' => ['link']], hint: 'NIMC sends the account login to this address, so it must be one you can open.', column: 'Email'),
                    self::field('field_to_modify', 'What needs correcting', type: 'select', options: [
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
                    // What the customer is asked next is decided by that answer,
                    // exactly as it is on the provider's own form: a name
                    // correction is never handed the box for an attestation, and
                    // a date of birth is never handed a bare "new value".
                    self::field('fname', 'Enter New First Name', rules: ['required', 'string', 'max:100'], showIf: ['field' => 'field_to_modify', 'any' => ['name', 'name_dob', 'name_phone', 'name_email', 'name_address']], column: 'First name'),
                    self::field('lname', 'Enter New Last Name', rules: ['required', 'string', 'max:100'], showIf: ['field' => 'field_to_modify', 'any' => ['name', 'name_dob', 'name_phone', 'name_email', 'name_address']], column: 'Last name'),
                    self::field('middle_name', 'Enter New Middle Name, if you use one', rules: ['nullable', 'string', 'max:100'], showIf: ['field' => 'field_to_modify', 'any' => ['name', 'name_dob', 'name_phone', 'name_email', 'name_address']], column: 'Middle name'),
                    self::field('phone', 'Enter New Phone Number', rules: ['required', 'string', 'max:20'], placeholder: '08031234567', showIf: ['field' => 'field_to_modify', 'any' => ['phone', 'name_phone', 'dob_phone']], column: 'New phone'),
                    self::field('email', 'Enter New Email Address', rules: ['required', 'email', 'max:255'], showIf: ['field' => 'field_to_modify', 'any' => ['email', 'name_email']], column: 'New email'),
                    self::field('address', 'Enter New Address', rules: ['required', 'string', 'max:255'], showIf: ['field' => 'field_to_modify', 'any' => ['address', 'name_address']], column: 'New address'),
                    self::field('date_of_birth', 'Enter New Date of Birth', type: 'date', rules: ['required', 'date'], showIf: ['field' => 'field_to_modify', 'any' => ['name_dob', 'dob_phone']], column: 'New DOB'),
                    // A date of birth on its own is the one correction NIMC will
                    // not take on a customer's word, so the birth attestation is
                    // asked for by number and by document. Their page does not
                    // restate the new date here - it reads it from the paper.
                    self::field('attestation_no', 'Enter the Birth Attestation Number', rules: ['required', 'string', 'max:30'], placeholder: 'eg. SO1250325358849A', showIf: ['field' => 'field_to_modify', 'any' => ['dob']], column: 'Attestation'),
                    self::field('attestation_img', 'Upload the Birth Attestation', type: 'file', rules: ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'], accept: '.jpg,.jpeg,.png', showIf: ['field' => 'field_to_modify', 'any' => ['dob']], hint: 'A clear photo or scan of the NIMC birth attestation, as jpg or png up to 2MB.', column: 'Attestation'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    self::field('delink_target', 'What you want done', type: 'select', options: [
                        'delink' => 'Delink my self service account',
                        'retrieve_email' => 'Retrieve the email on my self service account',
                    ], rules: ['required', 'in:delink,retrieve_email'], column: 'Type'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    self::field('tracking_id', 'Enter Tracking ID', rules: ['nullable', 'string', 'size:15', 'alpha_num'], column: 'Tracking ID'),
                    self::field('state', 'State of enrolment', rules: ['required', 'string', 'max:80']),
                    self::field('lga', 'LGA of enrolment', rules: ['nullable', 'string', 'max:80']),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                'consent' => [
                    'title' => 'Consent and authorisation',
                    'intro' => 'This prints your own bank verification record back to you. Confirm you understand what that means before the form opens.',
                    'clauses' => [
                        ...self::CONSENT_IDENTITY,
                        'BelovedSubP is not NIBSS or my bank, and the slip it produces is a reprint of my own data rather than a document issued by either of them.',
                        'The slip is for printing or saving as a copy. It must not be printed on plastic or offered to anyone as an identity card.',
                    ],
                ],
                'fields' => [
                    self::field('bvn', 'Enter the BVN Number', rules: ['required', 'digits:11'], placeholder: self::BVN_PLACEHOLDER, hint: 'The 11-digit Bank Verification Number.', column: 'BVN'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    // NIMC stopped issuing plastic cards, and the QR on this print
                    // is not theirs, so the only honest use of the file is a copy.
                    'Ordering this slip means you will print or save it as a PDF or photocopy, not onto plastic. NIMC has stopped issuing plastic ID cards, and the QR code on our print is not NIMC\'s own.',
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
                    self::field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, hint: 'The 11-digit National Identification Number.', column: 'NIN'),
                    // The four slips are four different jobs on their counter, each
                    // with its own rate, so the queue charges the one picked.
                    self::field('slip_type', 'Choose Slip Type', type: 'select', options: [
                        'long_slip' => 'NIN Slip (Long Slip)',
                        'standard_slip' => 'Standard NIN Slip',
                        'premium_slip' => 'Premium NIN Slip',
                        'vnin_slip' => 'Vnin NIN Slip Sample',
                    ], rules: ['required', 'in:long_slip,standard_slip,premium_slip,vnin_slip'], column: 'Slip Type'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('validation_type', 'Select Validation Category', type: 'select', options: [
                        'no_record' => 'No Record Found',
                        'update_record' => 'Update Record(Modification Validation Name/Phone/Address except DOB)',
                    ], rules: ['required', 'in:no_record,update_record'], column: 'Type'),
                    self::field('nin', 'Enter the NIN Number', rules: ['required', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                'sub_service' => true,
                'tiers' => [
                    'field' => 'retrieve_type',
                    'map' => [
                        'phone' => 'price_bvn_retrieve_phone',
                        'bms' => 'price_bvn_retrieve_bms',
                    ],
                ],
                'fields' => [
                    self::field('retrieve_type', 'Choose Category', type: 'select', options: [
                        'phone' => 'Using Phone Number',
                        'bms' => 'Using BMS Ticket',
                    ], rules: ['required', 'in:phone,bms'], column: 'Phone No / BMS'),
                    // The category is not decoration: a phone search is worked from
                    // a number and a ticket search is worked from the BMS number
                    // and its ticket, exactly as the wired page demands. Asked for
                    // together, the customer would have to fill boxes that decide
                    // nothing about their request.
                    self::field('phone', 'Enter Phone Number', rules: ['required', 'string', 'max:20'], placeholder: '08031234567', showIf: ['field' => 'retrieve_type', 'any' => ['phone']], column: 'Phone'),
                    self::field('bms_no', 'Enter BMS Number', rules: ['required', 'string', 'max:100'], showIf: ['field' => 'retrieve_type', 'any' => ['bms']], column: 'BMS No'),
                    self::field('ticket_id', 'Enter Ticket ID', rules: ['required', 'string', 'max:120'], showIf: ['field' => 'retrieve_type', 'any' => ['bms']], column: 'Ticket'),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('verification_type', 'Verification Type', type: 'select', options: [
                        'by_nin' => 'By NIN',
                        'by_phone' => 'By Phone No',
                        'by_demo' => 'By Demographic Info',
                    ], rules: ['required', 'in:by_nin,by_phone,by_demo'], column: 'Verified by'),
                    self::field('nin', 'Enter the NIN Number', rules: ['nullable', 'digits:11'], placeholder: self::NIN_PLACEHOLDER, column: 'NIN'),
                    self::field('phone', 'Enter Phone Number', rules: ['nullable', 'string', 'max:20'], column: 'Phone'),
                    self::field('firstname', 'First name', rules: ['nullable', 'string', 'max:120']),
                    self::field('lastname', 'Last name', rules: ['nullable', 'string', 'max:120']),
                    self::field('dob', 'Date of birth', rules: ['nullable', 'string', 'max:20']),
                    self::field('gender', 'Gender', rules: ['nullable', 'string', 'max:20']),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
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
                    self::field('bvn', 'BVN', rules: ['required', 'digits:11']),
                    self::field('firstname', 'First name', rules: ['nullable', 'string', 'max:120']),
                    self::field('lastname', 'Last name', rules: ['nullable', 'string', 'max:120']),
                    self::field('phone', 'Phone number', rules: ['nullable', 'string', 'max:20']),
                    self::field('notes', 'Anything else we should know', type: 'textarea', rules: ['nullable', 'string', 'max:1000']),
                ],
            ],
        ];
    }

    /**
     * The services as the owner has configured them.
     *
     * Every customer page, price input and queue screen reads this, so an edit
     * made in Admin reaches the form, the fee and the validation rules together
     * instead of only one of them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogue(): array
    {
        return app(ServiceFormRepository::class)->all();
    }

    public function find(string $slug): ?array
    {
        return $this->catalogue()[$slug] ?? null;
    }

    /**
     * The services a customer reaches as a page of their own, which is the only
     * kind an owner can pause: he ticks the service, its form is replaced by the
     * maintenance notice, and the checkout refuses it. A slip printed under the
     * NIN search or a retrieval under the BVN page belongs to that page's own
     * state, and the two verification endpoints answer the provider directly, so
     * a switch over them would promise something this method cannot deliver.
     *
     * @return array<string, string>
     */
    public function shelfServices(): array
    {
        $labels = [];

        foreach ($this->catalogue() as $slug => $definition) {
            if (!empty($definition['wired_only']) || !empty($definition['sub_service'])) {
                continue;
            }

            $labels[(string) $slug] = (string) $definition['title'];
        }

        return $labels;
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
        return Schema::priceKey($slug, $this->find($slug) ?? [], $submitted);
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
        $definition = $this->find($slug) ?? [];
        $options = Schema::priceOptions($definition);
        if ($options === []) {
            return [];
        }

        $grouped = [];
        foreach ($options as $entry) {
            $grouped[$entry['key']][] = $entry['label'];
        }

        $tiers = [];
        foreach ($grouped as $key => $labels) {
            $distinct = array_values(array_unique($labels));
            $tiers[] = [
                'key' => $key,
                // Options sharing one rate are described by that rate when the
                // owner named it, and by their count when he did not.
                'label' => count($distinct) === 1 ? $distinct[0] : count($labels).' options',
                'options' => $labels,
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
        $definition = $this->find($slug) ?? [];
        $prices = [];
        foreach (Schema::priceOptions($definition) as $option => $entry) {
            $prices[$option] = identity_tier_price($entry['key'], $this->servicePriceKey($slug))
                + $this->markupNaira($slug);
        }

        return $prices;
    }

    /**
     * What every branch of this service's form charges, grouped by the question
     * that fixes it. The page hands these to the browser so the fee names itself
     * the moment the customer chooses, and they never travel back as an answer.
     *
     * @return array<string, array<string, int>> field => option => kobo
     */
    public function branchPricesKobo(string $slug): array
    {
        $markupKobo = (int) round($this->markupNaira($slug) * 100);
        $prices = [];

        foreach (Schema::priceMap($this->find($slug) ?? []) as $field => $map) {
            foreach ($map as $option => $key) {
                $prices[$field][(string) $option] = (int) round(
                    identity_tier_price((string) $key, $this->servicePriceKey($slug)) * 100
                ) + $markupKobo;
            }
        }

        return $prices;
    }

    /** The fall-back figure the page quotes before any priced option is chosen. */
    public function basePriceKobo(string $slug): int
    {
        return (int) round($this->totalNaira($slug) * 100);
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

    /**
     * What the customer sent that belongs on the screen they were shown.
     *
     * This is the input the rules, the price and the stored request are all
     * worked from, so an answer copied out of a branch the form never opened
     * cannot reach any of them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function visibleInput(string $slug, array $input): array
    {
        return Schema::prune($this->find($slug) ?? [], $input);
    }

    /**
     * The documents this customer was asked for, which is everything the store
     * step needs and nothing more.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function fileFields(string $slug, array $input): array
    {
        return Schema::fileFields($this->find($slug) ?? [], $input);
    }

    /**
     * The same documents, with the flag that says whether the customer had to
     * supply each one - which is what decides whether a storage failure has to
     * be refunded or can simply be dropped.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{name: string, required: bool}>
     */
    public function uploadFields(string $slug, array $input): array
    {
        return Schema::uploadFields($this->find($slug) ?? [], $input);
    }

    /**
     * Rules for the branch the customer actually answered.
     *
     * A field the form never showed them is not required of them, and a value
     * that belongs to a different branch is refused rather than stored and
     * charged at the price of the branch it came from.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validationRules(string $slug, array $input = []): array
    {
        $service = $this->find($slug);
        if (!$service) {
            return [];
        }

        $rules = Schema::rules($service, $input);

        // A gated service is not gated if the server still accepts the post from
        // somebody who never saw the terms, so the tick is checked here too.
        if ($this->consent($slug)['clauses'] !== []) {
            $rules['consent_agreed'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * The terms that stand between a customer and this service's form. An empty
     * clause list means the service asks nothing beyond its own fields, so the
     * form renders straight away.
     *
     * @return array{title: string, intro: string, clauses: list<string>}
     */
    public function consent(string $slug): array
    {
        $consent = (array) ($this->find($slug)['consent'] ?? []);

        return [
            'title' => (string) ($consent['title'] ?? 'Consent and authorisation'),
            'intro' => (string) ($consent['intro'] ?? ''),
            'clauses' => array_values(array_filter((array) ($consent['clauses'] ?? []), 'is_string')),
        ];
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
    private static function field(
        string $name,
        string $label,
        string $type = 'text',
        array $rules = [],
        array $options = [],
        ?string $hint = null,
        ?string $placeholder = null,
        ?string $column = null,
        ?array $showIf = null,
        ?string $accept = null,
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
            // A question only asked for certain answers, and the documents a
            // certain answer needs. The provider's own form branches this way, so
            // a customer changing a name is not handed the box for an attestation.
            'show_if' => $showIf,
            'accept' => $accept,
        ];
    }
}
