<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ServiceFormRepository;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's half of the configurable form.
 *
 * A service's questions used to be code, so a new dropdown option meant a deploy.
 * These tests hold the two promises that replace it: what is written here is what
 * the customer then sees, and a save that is refused gives the owner back exactly
 * what they typed rather than throwing it away.
 */
class AdminServiceFormEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function member(int $kobo = 50_000_00): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function postedForm(string $title = 'NIN Delink'): array
    {
        return [
            'title' => $title,
            'group' => 'nin',
            'summary' => 'Unlink a self service account from your NIN.',
            'icon' => '⛓',
            'cta' => 'Send it',
            'cost_text' => 'It costs ₦{{price}} per request',
            'fields' => [
                0 => [
                    'name' => 'nin',
                    'label' => 'Enter the NIN Number',
                    'type' => 'text',
                    'rules' => 'required|digits:11',
                    'options' => [],
                    'prices' => [],
                ],
                1 => [
                    'name' => 'slip_type',
                    'label' => 'Which slip do you have',
                    'type' => 'select',
                    'rules' => 'required',
                    'options' => [
                        0 => ['value' => 'old', 'label' => 'OLD SLIP'],
                        1 => ['value' => 'new', 'label' => 'NEW SLIP'],
                    ],
                    'prices' => ['old' => 'price_manual_nin_delink'],
                ],
            ],
            'notes' => [
                0 => [
                    'text' => 'Bring the old slip if you still have it.',
                    'show_if' => ['field' => 'slip_type', 'any' => 'old'],
                ],
            ],
        ];
    }

    public function test_the_owner_can_open_the_list_and_one_service_from_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/services')
            ->assertOk()
            ->assertSee('Edit questions');

        $html = $this->actingAs($admin)->get('/admin/services/nin_delink')->assertOk()->getContent();

        // Every question on the page is numbered the way the form posts it, so
        // what the owner sees and what arrives at the server are the same rows.
        $this->assertStringContainsString('name="fields[0][name]"', $html);
        $this->assertStringContainsString('value="nin"', $html);
        $this->assertStringContainsString('name="fields[1][options][0][value]"', $html);
        $this->assertStringContainsString('name="fields[1][prices][delink]"', $html);
    }

    public function test_a_note_written_here_is_the_note_the_customer_reads(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/services/nin_delink', $this->postedForm())
            ->assertRedirect('/admin/services/nin_delink');

        $this->actingAs($this->member())->get('/vtu/manual/nin_delink')
            ->assertOk()
            ->assertSee('Bring the old slip if you still have it.');

        $definition = app(ServiceFormRepository::class)->find('nin_delink');

        $this->assertSame('Which slip do you have', $definition['fields'][1]['label']);
        $this->assertSame(
            ['old' => 'price_manual_nin_delink'],
            $definition['fields'][1]['price_by']['map'],
        );
        $this->assertSame('slip_type', $definition['notes'][0]['show_if']['field']);
        $this->assertSame(['old'], $definition['notes'][0]['show_if']['any']);

        // The editor shows the saved version back, note included.
        $this->actingAs($admin)->get('/admin/services/nin_delink')
            ->assertOk()
            ->assertSee('Bring the old slip if you still have it.', false);
    }

    public function test_a_refused_save_puts_the_owner_back_at_their_own_words(): void
    {
        $posted = $this->postedForm();
        $posted['fields'][0]['rules'] = 'required|digits:11|shrink';
        $posted['fields'][1]['label'] = 'Which slip have you got on you';

        $this->actingAs($this->admin())
            ->post('/admin/services/nin_delink', $posted)
            ->assertSessionHasErrors('fields.0.rules');

        // The flashed answers live for exactly one request, so the page is read
        // once rather than proved twice.
        $html = $this->openPage('/admin/services/nin_delink');

        $this->assertStringNotContainsString('Which slip do you have', $html);
        $this->assertStringContainsString('Which slip have you got on you', $html);
        $this->assertStringContainsString('Bring the old slip if you still have it.', $html);
        $this->assertStringContainsString('is not one this platform will run', $html);
    }

    public function test_an_empty_note_list_stays_empty_instead_of_bringing_back_the_shipped_ones(): void
    {
        $posted = $this->postedForm('NIN Personalization');
        $posted['notes'] = [];

        $this->actingAs($this->admin())->post('/admin/services/nin_personalization', $posted)
            ->assertRedirect();

        $definition = app(ServiceFormRepository::class)->find('nin_personalization');

        $this->assertSame([], $definition['notes']);
        $this->assertArrayNotHasKey('notices', $definition);
    }

    public function test_the_editor_is_reachable_from_the_pages_the_admin_already_uses(): void
    {
        $admin = $this->admin();

        $this->assertStringContainsString(
            '/admin/services',
            $this->openPage('/admin'),
        );

        $this->assertStringContainsString(
            '/admin/services',
            $this->openPage('/admin/settings'),
        );
    }

    public function test_the_saved_form_is_the_form_the_customer_has_to_fill_in(): void
    {
        $user = $this->member();

        $this->actingAs($this->admin())->post('/admin/services/nin_delink', $this->postedForm())
            ->assertRedirect();

        // The question the shipped form asked is gone, so answering it is no
        // longer enough - and an answer to a question that no longer exists is
        // simply not part of this service.
        $this->actingAs($user)->post('/vtu/manual/nin_delink', [
            PurchaseIntent::FIELD => PurchaseIntent::mint(),
            'nin' => '12345678901',
            'delink_target' => 'delink',
        ])->assertSessionHasErrors('slip_type');

        $this->actingAs($user)->post('/vtu/manual/nin_delink', [
            PurchaseIntent::FIELD => PurchaseIntent::mint(),
            'nin' => '12345678901',
            'slip_type' => 'old',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::query()->where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($order, 'The request the owner wrote never reached the queue.');
        $this->assertSame('pending', $order->status);
        $this->assertSame('12345678901', $order->customer_ref);
        $this->assertSame(
            50_000_00 - (int) $order->amount,
            (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
            'The customer was not charged the rate the owner attached to that choice.',
        );
    }

    /**
     * The two shapes a real identity service needs and a flat form cannot give
     * it: a second dropdown whose whole list depends on the first answer, and a
     * document the customer cannot submit without. Each has to survive the save,
     * the refresh of this page, and the trip to the customer's screen - the
     * dependent menu being the one that can silently arrive as an empty box.
     */
    public function test_a_dependent_menu_and_a_required_upload_survive_the_round_trip(): void
    {
        $posted = [
            'title' => 'NIN Modification',
            'group' => 'nin',
            'summary' => 'Change what is held against your NIN.',
            'icon' => '✏',
            'cta' => 'Send it',
            'cost_text' => 'It costs ₦{{price}}',
            'fields' => [
                0 => [
                    'name' => 'nin',
                    'label' => 'Enter the NIN Number',
                    'type' => 'text',
                    'rules' => 'required|digits:11',
                ],
                1 => [
                    'name' => 'field_to_modify',
                    'label' => 'Which detail is wrong',
                    'type' => 'select',
                    'rules' => 'required',
                    'options' => [
                        ['value' => 'phone', 'label' => 'Phone Number'],
                        ['value' => 'name', 'label' => 'Name Pair'],
                    ],
                ],
                2 => [
                    'name' => 'new_value',
                    'label' => 'Which line do you want on it',
                    'type' => 'select',
                    'rules' => 'required',
                    'options' => [],
                    'options_by' => [
                        [
                            'field' => 'field_to_modify',
                            'value' => 'phone',
                            'options' => "missed_line = The Line I Missed\nnew_line = A Brand New Line",
                        ],
                    ],
                    'prices' => ['new_line' => 'price_manual_nin_modification_name_pair'],
                ],
                3 => [
                    'name' => 'proof',
                    'label' => 'Upload the document',
                    'type' => 'file',
                    'rules' => 'required',
                    'accept' => '.pdf,.jpg,.png',
                ],
            ],
            'notes' => [],
        ];

        $this->actingAs($this->admin())
            ->post('/admin/services/nin_modification', $posted)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $definition = app(ServiceFormRepository::class)->find('nin_modification');

        $this->assertSame('field_to_modify', $definition['fields'][2]['options_by']['field']);
        $this->assertSame(
            ['missed_line' => 'The Line I Missed', 'new_line' => 'A Brand New Line'],
            $definition['fields'][2]['options_by']['map']['phone'],
        );
        $this->assertSame(
            'price_manual_nin_modification_name_pair',
            $definition['fields'][2]['price_by']['map']['new_line'],
        );
        $this->assertSame('.pdf,.jpg,.png', $definition['fields'][3]['accept']);
        $this->assertTrue($definition['fields'][3]['required']);

        // Refreshing the editor brings the owner's own words back rather than the
        // form the service was shipped with.
        $html = $this->openPage('/admin/services/nin_modification');
        $this->assertStringContainsString('missed_line = The Line I Missed', $html);
        $this->assertStringContainsString('name="fields[2][options_by][0][field]"', $html);
        $this->assertStringContainsString('.pdf,.jpg,.png', $html);

        // And the customer is handed the menu that only exists after the first
        // answer, in the same map the server walks.
        $page = $this->actingAs($this->member())
            ->get('/vtu/manual/nin_modification')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('accept=".pdf,.jpg,.png"', $page);

        $config = json_decode(
            preg_replace('/.*<script type="application\/json" data-service-form-config>(.*?)<\/script>.*/s', '$1', $page),
            true,
        );

        $this->assertSame('field_to_modify', $config['fields'][2]['optionsBy']['field']);
        $this->assertSame(
            ['missed_line', 'new_line'],
            array_column($config['fields'][2]['optionsBy']['map']['phone'], 'value'),
        );
        // Only the answer the owner priced carries a figure on the page; the other
        // one keeps the service's own rate. (What a key is worth is the engine's
        // business and is proved in ServiceFormCascadeTest.)
        $this->assertSame(['new_line'], array_keys($config['fields'][2]['priceFor']));
        $this->assertGreaterThan(0, $config['fields'][2]['priceFor']['new_line']);
    }

    private function openPage(string $path): string
    {
        return $this->actingAs($this->admin())->get($path)->assertOk()->getContent();
    }
}
