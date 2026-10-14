<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\ManualFulfilmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every key-less identity job is done by a person rather than an API, but the
 * customer must never learn that. What they get instead is a normal-looking
 * purchase page that states the price and how long the result takes.
 */
class ManualServicePageTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 5_000_00]);

        return $user->fresh();
    }

    public function test_every_manual_service_page_shows_a_price_and_a_wait_time(): void
    {
        $manual = app(ManualFulfilmentService::class);
        $user = $this->member();

        foreach ($manual->catalogue() as $slug => $service) {
            // Verification is ordered from its own wired page, which is also the
            // page that queues it when the owner has switched it to manual.
            if ($manual->wiredOnly($slug)) {
                $this->actingAs($user)->get('/vtu/manual/'.$slug)->assertNotFound();

                continue;
            }

            $html = $this->actingAs($user)->get('/vtu/manual/'.$slug)
                ->assertOk()
                ->getContent();

            $price = number_format($manual->totalNaira($slug), 2);
            $this->assertStringContainsString($price, $html, $slug.' does not state its price.');

            $turnaround = $manual->turnaroundLabel($slug);
            $this->assertStringContainsString($turnaround, $html, $slug.' does not promise a turnaround.');

            $this->assertStringContainsString($service['title'], $html);
        }
    }

    public function test_a_manual_service_asks_for_exactly_what_the_provider_asks_for(): void
    {
        $manual = app(ManualFulfilmentService::class);

        // Read off the JH Tech screens themselves. 'notes' is ours, not theirs, and
        // is always optional; anything else in here must be a field the provider
        // shows, because every extra box is one more reason for a customer to stop.
        $contract = [
            'ipe_clearance' => ['ipe_type', 'tracking_id', 'notes'],
            'nin_personalization' => ['tracking_id', 'category', 'notes'],
            'nin_slip_print' => ['nin', 'slip_type', 'notes'],
            'bvn_print' => ['bvn', 'notes'],
        ];

        foreach ($contract as $slug => $expected) {
            $this->assertSame(
                $expected,
                array_column($manual->find($slug)['fields'], 'name'),
                $slug.' does not match the fields the provider asks for.'
            );

            // The customer's contact details live on their account and on the
            // admin's detail page, so the form must not ask for them again.
            $this->actingAs($this->member())
                ->get('/vtu/manual/'.$slug)
                ->assertOk()
                ->assertDontSee('name="phone"', false)
                ->assertDontSee('name="email"', false);
        }

        // Two services ask more than their category, because the category decides
        // what the job is worked from: a correction writes new values into the
        // record, and a retrieval is searched either by phone number or by the BMS
        // number and its ticket. Both hold every question in the list below, but a
        // customer only ever sees the ones their own answer asks for, which
        // test_a_category_decides_which_identifier_the_service_demands proves for
        // the retrieval and NinModificationWorkflowTest for the correction.
        $branchContract = [
            'nin_modification' => [
                'nin', 'email_choice', 'link_email', 'field_to_modify',
                'fname', 'lname', 'middle_name', 'phone', 'email', 'address',
                'date_of_birth', 'attestation_no', 'attestation_img', 'notes',
            ],
            'bvn_retrieve' => ['retrieve_type', 'phone', 'bms_no', 'ticket_id', 'notes'],
        ];

        foreach ($branchContract as $slug => $expected) {
            $this->assertSame(
                $expected,
                array_column($manual->find($slug)['fields'], 'name'),
                $slug.' does not hold the questions the provider works from.'
            );
        }
    }

    /**
     * The screens a customer fills are the provider's screens: the same labels,
     * the same opening option in every dropdown, the same warnings about how long
     * a slip stays available. Only the price is ours, and it comes from the admin
     * settings page rather than from the provider's own number.
     */
    public function test_the_form_carries_the_providers_own_words_and_the_admins_own_price(): void
    {
        Setting::updateOrCreate(['key' => 'price_manual_bvn_print'], ['value' => '450']);
        settings_flush_cache();

        $html = $this->actingAs($this->member())
            ->get('/vtu/manual/bvn_print')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Enter the BVN Number', $html);
        $this->assertStringContainsString('placeholder="Enter BVN"', $html);
        $this->assertStringContainsString('Print BVN Slip Now', $html);

        $this->assertStringContainsString('It costs ₦450.00 per slip', $html);
        $this->assertStringNotContainsString('₦150', $html);
    }

    public function test_the_form_names_the_price_of_every_option_it_offers(): void
    {
        $html = $this->actingAs($this->member())
            ->get('/vtu/manual/nin_modification')
            ->assertOk()
            ->getContent();

        // The price line and the deduction warning both follow the correction the
        // customer picks, so every rate has to be in the page for the browser.
        preg_match('/data-service-form-config>(.*?)<\/script>/s', $html, $match);

        $this->assertNotEmpty($match[1] ?? '', 'The page carries no branch map.');

        $rates = [];
        foreach ((array) json_decode($match[1], true)['fields'] as $field) {
            foreach ((array) $field['priceFor'] as $option => $kobo) {
                $rates[$field['name']][$option] = $kobo;
            }
        }

        $this->assertEquals([
            'name' => 650_000,
            'phone' => 650_000,
            'address' => 650_000,
            'email' => 650_000,
            'name_phone' => 750_000,
            'name_email' => 750_000,
            'name_address' => 750_000,
            'name_dob' => 1_500_000,
            'dob_phone' => 1_500_000,
            'dob' => 4_000_000,
        ], $rates['field_to_modify'] ?? []);
    }

    public function test_a_page_of_differently_priced_options_does_not_claim_one_price_yet(): void
    {
        $html = $this->actingAs($this->member())
            ->get('/vtu/manual/nin_modification')
            ->assertOk()
            ->getContent();

        // Nothing has been chosen, so the job — and therefore the bill — is not
        // known. Quoting the cheapest option alone would read as a promise.
        $this->assertStringContainsString('₦6,500.00 – ₦40,000.00', $html);

        // A page that sells one job at one rate has no span to hide behind.
        $this->actingAs($this->member())
            ->get('/vtu/manual/ipe_clearance')
            ->assertOk()
            ->assertSee('₦3,000.00', false)
            ->assertDontSee('choose what you need done', false);
    }

    public function test_a_dropdown_opens_on_the_providers_own_wording(): void
    {
        $user = $this->member();

        $pages = [
            'ipe_clearance' => 'Select IPEs Category',
            'nin_validation' => 'Select Validation Category',
            'nin_slip_print' => 'Choose Slip Type',
            'bvn_retrieve' => 'Choose Category',
        ];

        foreach ($pages as $slug => $opening) {
            $this->actingAs($user)->get('/vtu/manual/'.$slug)
                ->assertOk()
                ->assertSee($opening, false);
        }

        // The provider's own option text, not our paraphrase of it.
        $this->actingAs($user)->get('/vtu/manual/ipe_clearance')
            ->assertOk()
            ->assertSee('New Enrollment for ID Retrieval', false)
            ->assertSee('Enrollment is Still Being Process', false);

        // Their slip menu and their validation menu name the products their own
        // way, and each of those names is a price of its own.
        $this->actingAs($user)->get('/vtu/manual/nin_slip_print')
            ->assertOk()
            ->assertSee('NIN Slip (Long Slip)', false)
            ->assertSee('Vnin NIN Slip Sample', false);

        $this->actingAs($user)->get('/vtu/manual/nin_validation')
            ->assertOk()
            ->assertSee('Update Record(Modification Validation Name/Phone/Address except DOB)', false);

        $this->actingAs($user)->get('/vtu/manual/nin_modification')
            ->assertOk()
            ->assertSee('Change of Phone Number', false)
            ->assertSee('Date of Birth and Phone Number', false);
    }

    public function test_the_providers_own_warnings_about_a_slip_are_shown(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get('/vtu/manual/nin_personalization')
            ->assertOk()
            ->assertSee('removed from our server one week after it is issued', false);

        $this->actingAs($user)->get('/vtu/manual/nin_slip_print')
            ->assertOk()
            ->assertSee('24 hours after it is issued', false)
            // NIMC stopped issuing plastic cards, and the QR on our print is not
            // theirs, so the provider states the only honest use before the sale.
            ->assertSee('not onto plastic', false);

        $this->actingAs($user)->get('/vtu/manual/nin_validation')
            ->assertOk()
            ->assertSee('Once the request has been sent it cannot be cancelled', false)
            ->assertSee('Submit NIN');
    }

    public function test_a_category_decides_which_identifier_the_service_demands(): void
    {
        $user = $this->member();
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 9_000_000]);
        $user = $user->fresh();

        // A phone search and a ticket search are different jobs at different rates,
        // so each asks only for what it is actually worked from - on the screen and
        // in the validator both.
        $phone = $this->actingAs($user)
            ->get('/vtu/manual/bvn_retrieve?retrieve_type=phone')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-form-field="phone" hidden', $phone);
        $this->assertStringContainsString('data-form-field="bms_no" hidden', $phone);
        $this->assertStringContainsString('data-form-field="ticket_id" hidden', $phone);

        $ticket = $this->actingAs($user)
            ->get('/vtu/manual/bvn_retrieve?retrieve_type=bms')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-form-field="phone" hidden', $ticket);
        $this->assertStringNotContainsString('data-form-field="bms_no" hidden', $ticket);
        $this->assertStringNotContainsString('data-form-field="ticket_id" hidden', $ticket);

        // The wired page asks for the BMS number and its ticket together, so a
        // ticket search that names neither half of it is refused and costs nothing.
        $this->actingAs($user)
            ->post('/vtu/manual/bvn_retrieve', ['retrieve_type' => 'bms', 'bms_no' => 'BMS-112233'])
            ->assertRedirect()
            ->assertSessionHasErrors('ticket_id');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)
            ->post('/vtu/manual/bvn_retrieve', ['retrieve_type' => 'phone', 'phone' => '08031234567'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['retrieve_type' => 'phone', 'phone' => '08031234567'],
            (array) Order::query()->where('user_id', $user->id)->sole()->meta['submitted'],
        );
    }

    public function test_a_manual_service_is_never_offered_for_free(): void
    {
        $manual = app(ManualFulfilmentService::class);

        foreach (array_keys($manual->catalogue()) as $slug) {
            $this->assertGreaterThan(
                0,
                $manual->totalNaira($slug),
                $slug.' would hand over paid-for labour at no cost.'
            );
        }
    }

    public function test_the_hub_only_links_services_a_customer_can_order(): void
    {
        $manual = app(ManualFulfilmentService::class);
        $hub = $this->actingAs($this->member())->get('/identity')->assertOk()->getContent();

        foreach ($manual->catalogue() as $slug => $service) {
            $visibleOnHub = str_contains($hub, '/vtu/manual/'.$slug);
            $this->assertSame(
                empty($service['hidden_from_hub']),
                $visibleOnHub,
                $slug.' should be '.(empty($service['hidden_from_hub']) ? 'on' : 'off').' the identity hub.'
            );
        }
    }

    public function test_the_wait_time_is_a_real_human_timescale(): void
    {
        $manual = app(ManualFulfilmentService::class);

        // The owner asked customers to be told roughly 48 hours; anything under
        // two hours would promise a speed no person working a dashboard has.
        foreach (array_keys($manual->catalogue()) as $slug) {
            $this->assertGreaterThanOrEqual(2, $manual->turnaroundHours($slug));
        }

        $this->assertSame('About 2 days', $manual->turnaroundLabel('nin_validation'));
    }
}
