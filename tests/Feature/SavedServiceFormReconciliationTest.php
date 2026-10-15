<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceForm;
use App\Models\User;
use App\Services\ServiceFormRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a saved service does when the code retires one of its questions.
 *
 * A stored row wins over the catalogue for every key it carries, so removing a
 * customer field in code only reaches a service the owner has never edited. These
 * tests hold the two halves of the fix: the retired question really goes, and
 * everything the owner wrote beside it stays exactly where they put it.
 */
class SavedServiceFormReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_form_keeps_the_retired_question_until_the_deployment_reconciles_it(): void
    {
        $this->store('nin_delink', $this->definitionWithRetiredQuestions());

        // Before the reconciliation the customer is still handed the box the code
        // no longer ships, which is precisely the silent undo a deploy must not do.
        $this->assertContains('notes', $this->fieldNames('nin_delink'));

        $this->reconcile();

        $after = $this->fieldNames('nin_delink');
        $this->assertNotContains('notes', $after);
        $this->assertNotContains('agent_code', $after);
        $this->assertSame(['nin', 'category', 'delivery_email'], $after);
    }

    public function test_reconciling_preserves_the_owners_own_wording_pricing_and_notices(): void
    {
        $this->store('nin_delink', $this->definitionWithRetiredQuestions());
        $before = $this->definition('nin_delink');

        $this->reconcile();

        $after = $this->definition('nin_delink');
        $kept = collect($after['fields'])->keyBy('name');

        // A question is only retired while it is still the shipped one. A reworded
        // label means the owner asked their own question, and it survives.
        $this->assertSame('How should we reach you when it is done?', $kept['delivery_email']['label']);
        $this->assertSame($before['price_key'], $after['price_key']);
        $this->assertSame($before['cost_text'], $after['cost_text']);
        $this->assertSame($before['notes'], $after['notes']);
        $this->assertSame($before['turnaround_default'], $after['turnaround_default']);
    }

    public function test_a_service_that_never_held_a_retired_question_is_left_untouched(): void
    {
        $this->store('nin_delink', [
            'price_key' => 'price_nin_delink',
            'fields' => [$this->field('nin', 'Enter the NIN Number')],
        ]);
        $saved = $this->stored('nin_delink');

        $order = Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'service_id' => Service::query()->create(['slug' => 'nin_delink', 'name' => 'NIN Delink'])->id,
            'customer_ref' => '12345678901',
            'amount' => 200_000,
            'profit' => 50_000,
            'provider' => 'manual',
            'status' => 'pending',
            'meta' => [
                'manual_queue' => true,
                'manual_service' => 'nin_delink',
                'submitted' => ['nin' => '12345678901', 'notes' => 'Please hurry.'],
            ],
        ]);

        $this->reconcile();

        $this->assertSame($saved, $this->stored('nin_delink'));

        // An answer a customer already paid for is a record, not a leftover of the
        // old form: retiring the question must never rewrite what was filed.
        $this->assertSame(
            ['nin' => '12345678901', 'notes' => 'Please hurry.'],
            $order->fresh()->meta['submitted'],
        );
    }

    public function test_the_customer_page_stops_showing_the_question_as_soon_as_it_is_retired(): void
    {
        $this->store('nin_delink', $this->definitionWithRetiredQuestions());

        // The catalogue is cached for a day, so a reconciliation that only writes
        // rows would leave the old form on screen until the cache happened to die.
        $this->assertContains('notes', array_column(
            app(ServiceFormRepository::class)->find('nin_delink')['fields'],
            'name',
        ));

        $this->reconcile();

        $page = app(ServiceFormRepository::class)->find('nin_delink');
        $this->assertNotContains('notes', array_column($page['fields'], 'name'));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function store(string $slug, array $definition): void
    {
        ServiceForm::query()->create([
            'slug' => $slug,
            'title' => 'NIN Delink',
            'group' => 'nin',
            'summary' => 'Unlink a self service account from your NIN.',
            'icon' => '⛓',
            'definition' => $definition,
            'visibility' => 'hub',
            'sort_order' => 10,
        ]);
    }

    /**
     * A row saved by the owner while the retired questions were still part of the
     * form, alongside the extra question and notices they added themselves.
     *
     * @return array<string, mixed>
     */
    private function definitionWithRetiredQuestions(): array
    {
        return [
            'price_key' => 'price_nin_delink',
            'cost_text' => 'It costs ₦{{price}} per request',
            'turnaround_default' => '1-3 working days',
            'notes' => ['NIMC can take longer at peak times.'],
            'fields' => [
                $this->field('nin', 'Enter the NIN Number'),
                $this->field('category', 'Select Category'),
                $this->field('notes', 'Anything else we should know', 'textarea'),
                $this->field('agent_code', 'Agent Code (Optional)'),
                $this->field('delivery_email', 'How should we reach you when it is done?'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $name, string $label, string $type = 'text'): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'rules' => $type === 'textarea' ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:100'],
            'options' => [],
            'hint' => null,
            'placeholder' => null,
            'column' => $label,
            'required' => $type !== 'textarea',
            'show_if' => null,
            'accept' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function fieldNames(string $slug): array
    {
        return array_map(
            static fn (array $field): string => (string) ($field['name'] ?? ''),
            $this->fields($slug),
        );
    }

    /**
     * The fields the owner's saved row carries, before the repository rewrites
     * anything for the page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fields(string $slug): array
    {
        return $this->definition($slug)['fields'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $slug): array
    {
        return (array) ServiceForm::query()->where('slug', $slug)->firstOrFail()->definition;
    }

    /**
     * The row exactly as the database holds it, so a test can prove that nothing
     * about a service nobody retired was rewritten.
     */
    private function stored(string $slug): array
    {
        return (array) DB::table('service_forms')->where('slug', $slug)->first();
    }

    private function reconcile(): void
    {
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_000002_retire_saved_customer_notes_fields.php');
    }
}
