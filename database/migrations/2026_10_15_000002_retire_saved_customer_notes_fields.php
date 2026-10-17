<?php

use App\Models\ServiceForm;
use App\Services\ServiceFormRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Take the retired customer questions out of the forms the owner has already
 * saved, without touching anything else they saved.
 *
 * A stored service row overrides the catalogue in code for every key it carries,
 * including its list of fields. So the eleven "Anything else we should know"
 * boxes that were removed from the shipped forms stay on screen for any service
 * the editor has saved at least once, and a deploy that only changes code would
 * silently undo itself on those services.
 *
 * Only a field that is still exactly the shipped question is removed. An owner
 * who reworded it asked a question of their own, and that is their decision to
 * keep. Nothing else on the row is looked at: prices, provider notices, the
 * other questions and every historical order stay as they are.
 */
return new class extends Migration
{
    /**
     * The customer questions that were retired, each with the wording the code
     * shipped. A field has to match both its name and its label to be removed.
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        'notes' => 'Anything else we should know',
        'agent_code' => 'Agent Code (Optional)',
    ];

    public function up(): void
    {
        $changed = [];

        ServiceForm::query()->orderBy('slug')->each(function (ServiceForm $row) use (&$changed): void {
            $definition = (array) $row->definition;
            $fields = (array) ($definition['fields'] ?? []);
            if ($fields === []) {
                return;
            }

            $kept = [];
            $removed = [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    $kept[] = $field;
                    continue;
                }

                $name = trim((string) ($field['name'] ?? ''));
                $label = trim((string) ($field['label'] ?? ''));
                $shipped = self::RETIRED[$name] ?? null;
                if ($shipped !== null && $shipped === $label) {
                    $removed[] = $name;
                    continue;
                }

                $kept[] = $field;
            }

            if ($removed === []) {
                return;
            }

            $definition['fields'] = $kept;
            $row->definition = $definition;
            $row->save();

            $changed[$row->slug] = $removed;
        });

        if ($changed === []) {
            return;
        }

        app(ServiceFormRepository::class)->flush();

        Log::info('Retired customer questions removed from saved service forms', [
            'count' => count($changed),
            'slugs' => $changed,
        ]);
    }

    /**
     * Not reversed on purpose. The question was retired as a decision about the
     * customer's page, so putting it back into every form that ever held it would
     * undo that decision for services nobody had customised. An owner who wants
     * such a box again adds it in the editor, where it is one deliberate field.
     */
    public function down(): void
    {
        //
    }
};
