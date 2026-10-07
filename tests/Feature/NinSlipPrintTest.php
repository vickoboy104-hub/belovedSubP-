<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\NinSlipLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NinSlipPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_printing_a_verified_record_is_drawn_here_and_never_costs_a_provider_call(): void
    {
        $user = $this->memberWithBalance(100_000);

        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/nin/print', [
                'slip_type' => 'standard_slip',
                'verification_order_id' => $this->verifiedRecord($user)->id,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => ['local_generated' => true, 'slip_type' => 'standard_slip'],
            ]);

        Http::assertNothingSent();

        $this->assertStringContainsString('/vtu/nin/slip/', (string) $response->json('data.slip_url'));

        // The shipped retail for a standard slip, and nothing on top of it.
        $this->assertSame(100_000 - 35_000, $this->balance($user));
        $this->assertSame(35_000, (int) $this->latestPrintOrder($user)->amount);
    }

    public function test_the_standard_slip_is_drawn_on_a_full_a4_sheet_from_the_stored_record(): void
    {
        $user = $this->memberWithBalance(100_000);
        $page = $this->slipPage($user, $this->printSlip($user, 'standard_slip'));

        $this->assertStringContainsString('size: 210mm 297mm; margin: 0;', $page);
        $this->assertStringContainsString('width: 210mm', $page);
        $this->assertStringContainsString('height: 297mm', $page);
        $this->assertStringContainsString('/images/nin/slips/standard-card.jpg', $page);

        // The words ESTHER_44075744122_Standard.pdf draws, each at its own line of
        // the card and in the fixed advance of the font the sample embeds.
        foreach ([
            'surname' => 'OKECHUKWU',
            'given_names' => 'ESTHER CHIOMA',
            'birthdate' => '19 MAR 2009',
            'nin_grouped' => '4407 574 4122',
        ] as $slot => $text) {
            $this->assertStringContainsString(
                $this->cellRun($text, 'standard_slip', $slot),
                $page,
                "The standard slip is missing {$slot}."
            );
        }
    }

    public function test_the_premium_slip_adds_the_gender_and_the_day_it_was_issued(): void
    {
        $user = $this->memberWithBalance(100_000);
        $page = $this->slipPage($user, $this->printSlip($user, 'premium_slip'));

        $this->assertStringContainsString('/images/nin/slips/premium-card.jpg', $page);
        $this->assertStringContainsString($this->cellRun('FEMALE', 'premium_slip', 'gender'), $page);
        $this->assertStringContainsString($this->cellRun('4407 574 4122', 'premium_slip', 'nin_grouped'), $page);

        // MUFTAU_…_Premium_Slip.pdf stamps the day the slip was issued, which for
        // us is the day the customer paid.
        $this->assertStringContainsString(
            $this->cellRun(strtoupper(now()->format('d M Y')), 'premium_slip', 'issue_date'),
            $page
        );

        // The green card artwork has a QR burned into it. It has to be covered by
        // this customer's own code, not left showing whoever the template was cut
        // from.
        $this->assertStringContainsString('class="nin-qr"', $page);
        $this->assertStringContainsString('api.qrserver.com/v1/create-qr-code/', $page);
    }

    public function test_the_long_slip_prints_the_record_as_proportional_text(): void
    {
        $user = $this->memberWithBalance(100_000);
        $verified = $this->verifiedRecord($user, [
            'nin' => '49979338424',
            'first_name' => 'BONIFACE',
            'middle_name' => 'ALEKE',
            'last_name' => 'OKOLO',
            'gender' => 'MALE',
            'tracking_id' => 'S7Y0OG2UI000WEK',
            'address_line_1' => 'PLOT 163/164 NEW OGBEDE LAYOUT',
            'residence_town' => 'ENUMA',
            'residence_state' => 'Enugu',
        ]);

        $page = $this->slipPage($user, $this->printSlip($user, 'long_slip', $verified));

        $this->assertStringContainsString('/images/nin/slips/long-form.jpg', $page);

        // BONIFACE_49979338424_Long_Slip.pdf breaks the street over two runs at one
        // left edge, and the state keeps a row of its own.
        foreach (['PLOT 163/164 NEW OGBEDE', 'LAYOUT ENUMA', 'Enugu', 'S7Y0OG2UI000WEK', '49979338424', 'OKOLO', 'ALEKE'] as $text) {
            $this->assertStringContainsString('>'.$text.'<', $page);
        }

        // Helvetica Neue is not a fixed-width face, so no cell boxes and no QR.
        $this->assertStringNotContainsString('<span class="nin-cell"', $page);
        $this->assertStringNotContainsString('class="nin-qr"', $page);
    }

    public function test_the_long_slip_breaks_a_street_the_way_the_sample_does(): void
    {
        $user = $this->memberWithBalance(100_000);
        $verified = $this->verifiedRecord($user, [
            'address_line_1' => 'PLOT 163/164 NEW OGBEDE LAYOUT',
            'residence_town' => 'ENUGU',
        ]);

        $page = $this->slipPage($user, $this->printSlip($user, 'long_slip', $verified));

        // BONIFACE_49979338424_Long_Slip.pdf breaks this street after OGBEDE and
        // carries LAYOUT ENUGU onto the row below it. Twenty-four characters is the
        // measure that reproduces those two rows.
        $this->assertStringContainsString('>PLOT 163/164 NEW OGBEDE<', $page);
        $this->assertStringContainsString('>LAYOUT ENUGU<', $page);
    }

    public function test_an_address_longer_than_the_card_holds_is_cut_not_run_off(): void
    {
        $user = $this->memberWithBalance(100_000);
        $verified = $this->verifiedRecord($user, [
            'address_line_1' => 'ALPHA BRAVO CHARLIE DELTA ECHO FOXTROT GOLF HOTEL INDIA JULIET KILO LIMA MIKE NOVEMBER OSCAR PAPA QUEBEC',
        ]);

        $page = $this->slipPage($user, $this->printSlip($user, 'long_slip', $verified));

        // The address cell on the long slip is four rows deep, so a fifth row would
        // print over the row the state occupies.
        $this->assertSame(4, NinSlipLayout::maxAddressLines('long_slip'));
        $this->assertStringContainsString('>ALPHA BRAVO CHARLIE<', $page);
        $this->assertStringNotContainsString('QUEBEC', $page);
    }

    public function test_a_customer_can_reprint_a_slip_they_already_paid_for(): void
    {
        $user = $this->memberWithBalance(100_000);
        $print = $this->printSlip($user, 'standard_slip');

        $before = $this->balance($user);
        $orders = Order::query()->where('user_id', $user->id)->count();

        $this->slipPage($user, $print);
        $this->slipPage($user, $print);

        $this->assertSame($before, $this->balance($user));
        $this->assertSame($orders, Order::query()->where('user_id', $user->id)->count());
    }

    public function test_a_slip_is_not_readable_by_another_customer(): void
    {
        $order = $this->printOrder($this->memberWithBalance(100_000), 'standard_slip');

        $this->actingAs($this->memberWithBalance(100_000))
            ->get('/vtu/nin/slip/'.$order->id)
            ->assertNotFound();
    }

    public function test_a_slip_is_not_readable_by_someone_not_signed_in(): void
    {
        $order = $this->printOrder($this->memberWithBalance(100_000), 'standard_slip');

        // No actingAs in this test, so the request really is a guest's.
        $this->get('/vtu/nin/slip/'.$order->id)->assertRedirect();
    }

    public function test_a_slip_type_this_site_does_not_draw_has_no_page(): void
    {
        $user = $this->memberWithBalance(100_000);
        $verified = $this->verifiedRecord($user);

        $order = Order::create([
            'user_id' => $user->id,
            'service_id' => $this->ninService()->id,
            'customer_ref' => $verified->customer_ref,
            'amount' => 100,
            'status' => 'success',
            'provider' => 'nin_api',
            'meta' => [
                'type' => 'nin',
                'service_type' => 'print',
                'slip_type' => 'vnin_slip',
                'normalized' => $verified->meta['normalized'],
            ],
        ]);

        $this->actingAs($user)->get('/vtu/nin/slip/'.$order->id)->assertNotFound();
    }

    public function test_the_print_price_the_owner_sets_is_the_price_the_customer_pays(): void
    {
        Setting::create(['key' => 'price_nin_slip_standard', 'value' => '500']);
        settings_flush_cache();

        $user = $this->memberWithBalance(100_000);
        $this->printSlip($user, 'standard_slip');

        $this->assertSame(100_000 - 50_000, $this->balance($user));
    }

    private function printSlip(User $user, string $slipType, ?Order $verified = null): string
    {
        $response = $this
            ->actingAs($user)
            ->postJson('/vtu/nin/print', [
                'slip_type' => $slipType,
                'verification_order_id' => ($verified ?? $this->verifiedRecord($user))->id,
            ])
            ->assertOk();

        return (string) $response->json('data.slip_url');
    }

    private function slipPage(User $user, string $url): string
    {
        return $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    /**
     * A paid slip order, built in the database rather than through the print
     * route, so a test that needs to be signed out is not left signed in by the
     * actingAs the route would otherwise need.
     */
    private function printOrder(User $user, string $slipType): Order
    {
        $verified = $this->verifiedRecord($user);

        return Order::create([
            'user_id' => $user->id,
            'service_id' => $this->ninService()->id,
            'customer_ref' => $verified->customer_ref,
            'amount' => 35_000,
            'status' => 'success',
            'provider' => 'nin_api',
            'meta' => [
                'type' => 'nin',
                'service_type' => 'print',
                'slip_type' => $slipType,
                'normalized' => $verified->meta['normalized'],
                'provider_data' => $verified->meta['provider_response']['data'],
            ],
        ]);
    }

    /**
     * A record left behind by a verification, holding the profile the provider
     * answered for ESTHER_44075744122_Standard.pdf.
     */
    private function verifiedRecord(User $user, array $overrides = []): Order
    {
        $normalized = array_merge([
            'full_name' => 'ESTHER CHIOMA OKECHUKWU',
            'nin' => '44075744122',
            'first_name' => 'ESTHER CHIOMA',
            'middle_name' => '',
            'last_name' => 'OKECHUKWU',
            'gender' => 'FEMALE',
            'birthdate' => '2009-03-19',
            'tracking_id' => 'A1B2C3D4E5F6G7H',
            'residence_state' => 'Anambra',
            'address_line_1' => '12 ZIK AVENUE',
        ], $overrides);

        return Order::create([
            'user_id' => $user->id,
            'service_id' => $this->ninService()->id,
            'customer_ref' => $normalized['nin'],
            'amount' => 25_000,
            'status' => 'success',
            'provider' => 'nin_api',
            'meta' => [
                'type' => 'nin',
                'service_type' => 'verify',
                'verification_type' => 'by_nin',
                'normalized' => $normalized,
                'provider_response' => ['success' => true, 'data' => $normalized],
            ],
        ]);
    }

    private function ninService(): Service
    {
        return Service::query()->firstOrCreate(
            ['slug' => 'nin_verify'],
            ['name' => 'NIN Verification']
        );
    }

    private function latestPrintOrder(User $user): Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('meta->service_type', 'print')
            ->latest('id')
            ->firstOrFail();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    /**
     * The page prints a monospace word one character at a time, each in a cell of
     * the embedded font's own advance, so the run lands where the sample puts it
     * whichever face the customer's browser has.
     */
    private function cellRun(string $text, string $slipType, string $slot): string
    {
        $layout = NinSlipLayout::millimetres($slipType);
        $field = collect($layout['fields'])->firstWhere('slot', $slot);

        $this->assertNotNull($field, "The {$slipType} layout has no {$slot} slot.");

        if ($layout['font'] !== 'monospace') {
            return '>'.$text.'<';
        }

        $width = round($field['size'] * NinSlipLayout::MONOSPACE_ADVANCE_EM, 4);

        $run = '';
        foreach (str_split($text) as $character) {
            $run .= '<span class="nin-cell" style="width: '.$width.'mm;">'.$character.'</span>';
        }

        return '>'.$run.'<';
    }
}
