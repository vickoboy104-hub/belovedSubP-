<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ServiceFormRepository;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A service is allowed to ask a question, then ask a different question based on
 * the answer, then charge the price that answer selected. Both halves have to be
 * true at once: the customer must only ever see the branch they are standing on,
 * and the money must be taken for that same branch rather than for whatever the
 * request claims.
 *
 * These tests run against a service the owner saved in Admin, not one written in
 * code, so they test the engine rather than the current catalogue.
 */
class ServiceFormCascadeTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'cascade_probe';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        Setting::updateOrCreate(['key' => 'price_manual_'.self::SLUG], ['value' => '1000']);
        Setting::updateOrCreate(['key' => 'price_cascade_slip'], ['value' => '1200']);
        Setting::updateOrCreate(['key' => 'price_cascade_change'], ['value' => '2500']);
        Setting::updateOrCreate(['key' => 'price_cascade_long'], ['value' => '3000']);
        Setting::updateOrCreate(['key' => 'price_cascade_standard'], ['value' => '1500']);
        settings_flush_cache();

        $this->editor()->persist(self::SLUG, $this->definition());
    }

    private function editor(): ServiceFormRepository
    {
        return app(ServiceFormRepository::class);
    }

    /**
     * Three levels, the way the provider's own submission page works: the number,
     * then what to do with it, then - only if the job is a print - which slip.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'title' => 'Cascade Probe',
            'group' => 'nin',
            'summary' => 'A service whose questions depend on each other.',
            'cta' => 'Send it',
            'fields' => [
                [
                    'name' => 'nin',
                    'label' => 'Enter the NIN Number',
                    'type' => 'text',
                    'rules' => ['required', 'digits:11'],
                    'placeholder' => 'Enter NIN',
                    'column' => 'NIN',
                ],
                [
                    'name' => 'job',
                    'label' => 'What do you need done?',
                    'type' => 'select',
                    'rules' => ['required', 'in:slip,change'],
                    'options' => ['slip' => 'Print a slip', 'change' => 'Change a detail'],
                    'price_by' => [
                        'map' => ['slip' => 'price_cascade_slip', 'change' => 'price_cascade_change'],
                    ],
                ],
                [
                    'name' => 'slip_type',
                    'label' => 'Choose Slip Type',
                    'type' => 'select',
                    'rules' => ['required'],
                    'options_by' => [
                        'field' => 'job',
                        'map' => [
                            'slip' => ['long' => 'Long Slip', 'standard' => 'Standard Slip'],
                        ],
                    ],
                    'show_if' => ['field' => 'job', 'any' => ['slip']],
                    'price_by' => [
                        'map' => ['long' => 'price_cascade_long', 'standard' => 'price_cascade_standard'],
                    ],
                ],
                [
                    'name' => 'new_name',
                    'label' => 'Enter the new name',
                    'type' => 'text',
                    'rules' => ['required', 'string', 'max:60'],
                    'show_if' => ['field' => 'job', 'any' => ['change']],
                ],
            ],
        ];
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    public function test_a_question_only_appears_after_the_answer_that_asks_for_it(): void
    {
        $page = $this->actingAs($this->memberWithBalance(500_000))
            ->get('/vtu/manual/'.self::SLUG)
            ->assertOk()
            ->getContent();

        // Nothing has been chosen, so neither branch question exists yet, and the
        // slip menu holds nothing the customer could accidentally pick. (The map
        // at the foot of the form still describes both branches - that is what
        // lets the browser move without a page load once a choice is made.)
        $this->assertStringContainsString('data-form-field="slip_type" hidden', $page);
        $this->assertStringContainsString('data-form-field="new_name" hidden', $page);
        $this->assertStringNotContainsString('>Long Slip<', $page);
        $this->assertStringNotContainsString('<option value="long"', $page);

        // Their number and their first choice come back on a page that was
        // rejected, so they are returned to the question they were answering.
        $branch = $this->actingAs($this->memberWithBalance(500_000))
            ->get('/vtu/manual/'.self::SLUG.'?job=slip')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-form-field="slip_type"', $branch);
        $this->assertStringNotContainsString('data-form-field="slip_type" hidden', $branch);
        $this->assertStringContainsString('>Long Slip<', $branch);
        $this->assertStringContainsString('>Standard Slip<', $branch);

        // The other branch is still nowhere on this page: it is out of sight and
        // out of the request, so it cannot be filled by accident or by hand.
        $this->assertStringContainsString('data-form-field="new_name" hidden', $branch);
        $this->assertMatchesRegularExpression('/name="new_name"[^>]*disabled/', $branch);
    }

    public function test_the_deepest_answer_is_the_one_that_sets_the_price(): void
    {
        $user = $this->memberWithBalance(500_000);

        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'slip',
                'slip_type' => 'long',
            ])
            ->assertRedirect();

        // ₦3,000 for the long slip, not ₦1,200 for printing in general and not
        // the ₦1,000 the page quoted before anything was chosen.
        $this->assertSame(300_000, (int) Order::query()->where('user_id', $user->id)->value('amount'));
        $this->assertSame(200_000, $this->balance($user));
    }

    public function test_a_second_branch_is_charged_at_its_own_rate(): void
    {
        $user = $this->memberWithBalance(500_000);

        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'change',
                'new_name' => 'A New Name',
            ])
            ->assertRedirect();

        $this->assertSame(250_000, (int) Order::query()->where('user_id', $user->id)->value('amount'));

        $submitted = (array) (Order::query()->where('user_id', $user->id)->first()?->meta['submitted'] ?? []);
        $this->assertSame(['nin' => '12345678901', 'job' => 'change', 'new_name' => 'A New Name'], $submitted);
    }

    public function test_a_question_the_customer_never_saw_is_not_demanded_of_them(): void
    {
        $user = $this->memberWithBalance(500_000);

        // Printing a slip asks for the slip type, never for the new name.
        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'slip',
                'slip_type' => 'standard',
            ])
            ->assertRedirect();

        $this->assertSame(150_000, (int) Order::query()->where('user_id', $user->id)->value('amount'));
        $this->assertSame(350_000, $this->balance($user));
    }

    public function test_an_answer_from_another_branch_is_refused_rather_than_mispriced(): void
    {
        $user = $this->memberWithBalance(500_000);

        // "name" is not a slip this service prints - it is the answer the other
        // branch would have gone on to ask for.
        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'slip',
                'slip_type' => 'name',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('slip_type');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(500_000, $this->balance($user));
    }

    public function test_a_branch_that_is_not_on_the_customer_s_screen_cannot_be_paid_for_or_stored(): void
    {
        $user = $this->memberWithBalance(500_000);

        // The long slip is ₦3,000 and the correction is ₦2,500. Sending both
        // answers at once must not book the more expensive one, and the answer
        // the form never showed must not reach the agent's queue either.
        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'change',
                'new_name' => 'A New Name',
                'slip_type' => 'long',
            ])
            ->assertRedirect();

        $order = Order::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($order);
        $this->assertSame(250_000, (int) $order->amount);

        $submitted = (array) ($order->meta['submitted'] ?? []);
        $this->assertArrayNotHasKey('slip_type', $submitted);
        $this->assertSame('change', $submitted['job'] ?? null);
    }

    public function test_the_page_hands_the_browser_the_same_map_the_server_walks(): void
    {
        $page = $this->actingAs($this->memberWithBalance(500_000))
            ->get('/vtu/manual/'.self::SLUG)
            ->assertOk()
            ->getContent();

        preg_match('/data-service-form-config>(.*?)<\/script>/s', $page, $match);

        $this->assertNotEmpty($match[1] ?? '', 'The page carries no branch map.');

        $config = json_decode($match[1], true);
        $fields = collect($config['fields'])->keyBy('name');

        $this->assertSame(['field' => 'job', 'any' => ['slip']], $fields['slip_type']['showIf']);
        $this->assertSame(['long' => 300_000, 'standard' => 150_000], $fields['slip_type']['priceFor']);
        $this->assertSame(
            ['slip' => [['value' => 'long', 'label' => 'Long Slip'], ['value' => 'standard', 'label' => 'Standard Slip']]],
            $fields['slip_type']['optionsBy']['map'],
        );

        // What the page quotes before anything is chosen is the lowest rate any
        // of its choices carries, not the service's own standing rate.
        $this->assertSame(100_000, $config['basePrice']);
        $this->assertSame(120_000, $config['floorPrice']);
    }

    public function test_the_price_line_and_the_deduction_warning_both_follow_the_choice(): void
    {
        $long = $this->costLineOf($this->branch('?job=slip&slip_type=long'));

        // Standing on the long slip, the page states that slip and nothing else:
        // neither the wider span nor the invitation to choose.
        $this->assertStringContainsString('₦3,000.00', $long);
        $this->assertStringNotContainsString('choose what you need done', $long);
        $this->assertStringNotContainsString('2,500.00', $long);

        // The other branch quotes its own figure, not this one's.
        $standard = $this->costLineOf($this->branch('?job=slip&slip_type=standard'));
        $this->assertStringContainsString('₦1,500.00', $standard);
        $this->assertStringNotContainsString('choose what you need done', $standard);

        // A correction on its own already carries a rate, so it too states one
        // figure rather than the shop window.
        $change = $this->costLineOf($this->branch('?job=change'));
        $this->assertStringContainsString('₦2,500.00', $change);
        $this->assertStringNotContainsString('choose what you need done', $change);

        // Nothing chosen yet, several rates on the page, so the span is named
        // rather than its cheapest corner, and the customer is asked to choose.
        $fresh = $this->costLineOf($this->branch(''));
        $this->assertStringContainsString('₦1,200.00 – ₦3,000.00', $fresh);
        $this->assertStringContainsString('choose what you need done', $fresh);

        // The figure the wallet is warned against is the same one the line gives.
        $page = $this->branch('?job=slip&slip_type=long');
        $this->assertStringContainsString('id="servicePayableAmount">3,000.00<', $page);

        // The warning before a choice carries the same span as the line above it,
        // and never the service's own rate: no job on this page costs ₦1,000, so
        // quoting it would both mislead the customer and refuse a wallet that
        // can pay for every job here.
        $openPage = $this->branch('');
        $this->assertStringContainsString('id="servicePayableAmount">1,200.00 – ₦3,000.00<', $openPage);
        $this->assertStringNotContainsString('id="servicePayableAmount">1,000.00<', $openPage);
    }

    public function test_a_page_whose_choices_all_cost_the_same_quotes_that_figure_not_its_base_rate(): void
    {
        $definition = $this->definition();
        $definition['fields'][1]['price_by']['map'] = [
            'slip' => 'price_cascade_slip',
            'change' => 'price_cascade_slip',
        ];
        $definition['fields'][2]['price_by']['map'] = [
            'long' => 'price_cascade_slip',
            'standard' => 'price_cascade_slip',
        ];
        $this->editor()->persist(self::SLUG, $definition);

        // One rate for every choice is no span to name, so the page states the
        // figure it will actually charge - the choices' ₦1,200 - rather than the
        // ₦1,000 the service carries as its own standing rate.
        $page = $this->branch('');
        $this->assertStringContainsString('id="servicePayableAmount">1,200.00<', $page);
        $this->assertStringNotContainsString('id="servicePayableAmount">1,000.00<', $page);
        $this->assertStringNotContainsString('choose what you need done', $this->costLineOf($page));
    }

    private function branch(string $query): string
    {
        return $this->actingAs($this->memberWithBalance(500_000))
            ->get('/vtu/manual/'.self::SLUG.$query)
            ->assertOk()
            ->getContent();
    }

    /**
     * The one line the customer reads as a price. The map at the foot of the form
     * describes every branch, including the sentence used before a choice is
     * made, so the whole page is not where to look for what is on the screen.
     */
    private function costLineOf(string $html): string
    {
        preg_match('/id="serviceCostLine"[^>]*>(.*?)<\/p>/s', $html, $match);

        $this->assertNotEmpty($match[1] ?? '', 'The page carries no price line.');

        return html_entity_decode($match[1]);
    }

    public function test_an_answer_the_owner_removed_stops_being_accepted(): void
    {
        $definition = $this->definition();
        $definition['fields'][2]['options_by']['map']['slip'] = [
            'standard' => 'Standard Slip',
        ];
        $definition['fields'][2]['price_by']['map'] = [
            'standard' => 'price_cascade_standard',
        ];

        $this->editor()->persist(self::SLUG, $definition);

        $user = $this->memberWithBalance(500_000);

        $page = $this->actingAs($user)->get('/vtu/manual/'.self::SLUG.'?job=slip')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Long Slip<', $page);
        $this->assertStringNotContainsString('"long"', $page);

        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SLUG, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'job' => 'slip',
                'slip_type' => 'long',
            ])
            ->assertSessionHasErrors('slip_type');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
    }
}
