<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceForm;
use App\Services\ManualFulfilmentService;
use App\Services\ServiceFormRepository;
use App\Support\ServiceForm\Schema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Where the owner writes what a service asks for.
 *
 * A service's questions, the answers that reveal further questions, the menus
 * that depend on an answer above them and the rate each answer selects are all
 * held in one stored definition, so adding a second dropdown to NIN
 * Modification is a page edited here rather than a deploy.
 *
 * What is deliberately NOT edited here: the amounts themselves. Every rate a
 * choice selects stays a setting on the Settings page, named by the key this
 * editor shows beside it, so one number never lives in two places. Turnaround
 * and the pause switch stay there for the same reason.
 */
class ServiceFormsController extends Controller
{
    /** The question types a service may ask, in the words an owner picks from. */
    private const TYPES = [
        'text' => 'One line of text',
        'textarea' => 'Several lines of text',
        'select' => 'A dropdown of choices',
        'number' => 'A number',
        'date' => 'A date',
        'email' => 'An email address',
        'file' => 'A document the customer uploads',
        'checkbox' => 'A single tick',
    ];

    /**
     * The rule names an owner is allowed to write. A saved definition is handed
     * to Laravel's validator, so anything outside this list is refused before it
     * is stored rather than judged when a customer submits.
     */
    private const ALLOWED_RULES = [
        'required', 'nullable', 'string', 'integer', 'numeric', 'boolean',
        'digits', 'digits_between', 'alpha_num', 'alpha_dash', 'min', 'max', 'size',
        'email', 'url', 'date', 'in', 'regex', 'accepted', 'confirmed',
    ];

    /** Names the platform itself uses inside every form POST. */
    private const RESERVED_NAMES = ['consent_agreed', '_token'];

    public function __construct(
        private readonly ManualFulfilmentService $manual,
        private readonly ServiceFormRepository $forms,
    ) {
    }

    public function index()
    {
        $groups = [];
        foreach ($this->forms->all() as $slug => $definition) {
            $group = (string) ($definition['group'] ?? 'other');
            $groups[$group] ??= [];
            $groups[$group][] = [
                'slug' => $slug,
                'title' => (string) ($definition['title'] ?? $slug),
                'summary' => (string) ($definition['summary'] ?? ''),
                'questions' => count(Schema::fields($definition)),
                'rates' => count($this->manual->priceTiers($slug)),
                'edited' => ServiceForm::query()->where('slug', $slug)->exists(),
                'on_hub' => !$this->forms->isHidden($slug),
                'page' => $this->forms->wiredOnly($slug) ? null : route('vtu.manual.form', $slug),
            ];
        }

        return view('admin.services.index', [
            'groups' => $groups,
            'edited' => ServiceForm::query()->pluck('slug')->all(),
        ]);
    }

    public function edit(string $service, Request $request)
    {
        $definition = $this->forms->find($service);
        abort_unless($definition !== null, 404);

        // A refused save brings the owner back to this page, and the work they
        // just typed has to be on it: a message about the eleventh question is
        // worth little if the first ten were thrown away to read it.
        $posted = $request->old('fields');

        $schema = is_array($posted)
            ? Schema::normalize([
                'fields' => $this->rowsForDisplay($posted),
                'notes' => (array) $request->old('notes', []),
            ])
            : Schema::normalize($definition);

        return view('admin.services.edit', [
            'slug' => $service,
            'definition' => $definition,
            'saved' => ServiceForm::query()->where('slug', $service)->exists(),
            'types' => self::TYPES,
            'rows' => Schema::fields($schema),
            'editorNotes' => (array) ($schema['notes'] ?? []),
            // Where each choice's money is kept, so this page can point at it
            // instead of holding a second copy of the same number.
            'rates' => collect($this->manual->priceTiers($service))->keyBy('key'),
        ]);
    }

    public function update(string $service, Request $request)
    {
        $definition = $this->forms->find($service);
        abort_unless($definition !== null, 404);

        $posted = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'group' => ['required', 'string', 'max:40'],
            'summary' => ['nullable', 'string', 'max:400'],
            'icon' => ['nullable', 'string', 'max:8'],
            'cta' => ['nullable', 'string', 'max:60'],
            'cost_text' => ['nullable', 'string', 'max:200'],
            'fields' => ['present', 'array', 'min:1', 'max:14'],
            'fields.*.name' => ['required', 'string', 'max:40'],
            'fields.*.label' => ['required', 'string', 'max:160'],
            'fields.*.type' => ['required', 'string', 'max:20'],
            'fields.*.rules' => ['nullable', 'string', 'max:600'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:160'],
            'fields.*.hint' => ['nullable', 'string', 'max:400'],
            'fields.*.column' => ['nullable', 'string', 'max:60'],
            'fields.*.accept' => ['nullable', 'string', 'max:120'],
            'fields.*.options' => ['nullable', 'array', 'max:40'],
            'fields.*.options.*.value' => ['nullable', 'string', 'max:60'],
            'fields.*.options.*.label' => ['nullable', 'string', 'max:160'],
            'fields.*.prices' => ['nullable', 'array', 'max:40'],
            'fields.*.prices.*' => ['nullable', 'string', 'max:70'],
            'fields.*.show_if.field' => ['nullable', 'string', 'max:40'],
            'fields.*.show_if.any' => ['nullable', 'string', 'max:400'],
            'fields.*.options_by' => ['nullable', 'array', 'max:14'],
            'fields.*.options_by.*.field' => ['nullable', 'string', 'max:40'],
            'fields.*.options_by.*.value' => ['nullable', 'string', 'max:60'],
            'fields.*.options_by.*.options' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'array', 'max:12'],
            'notes.*.text' => ['nullable', 'string', 'max:600'],
            'notes.*.show_if.field' => ['nullable', 'string', 'max:40'],
            'notes.*.show_if.any' => ['nullable', 'string', 'max:400'],
        ]);

        $compiled = $this->compile($posted);

        $this->forms->persist($service, array_merge($compiled, [
            'title' => $posted['title'],
            'group' => $posted['group'],
            'summary' => (string) ($posted['summary'] ?? ''),
            'icon' => (string) ($posted['icon'] ?? ''),
            'cta' => (string) ($posted['cta'] ?? '') !== '' ? $posted['cta'] : null,
            'cost_text' => (string) ($posted['cost_text'] ?? '') !== '' ? $posted['cost_text'] : null,
        ]));

        return redirect()
            ->route('admin.services.edit', $service)
            ->with('success', $posted['title'].' saved. Customers now see these questions.');
    }

    /**
     * Take the saved service back out of the editor and let the code version of
     * it show through again.
     */
    public function reset(string $service, Request $request)
    {
        abort_unless($this->forms->find($service) !== null, 404);

        $this->forms->delete($service);

        return redirect()
            ->route('admin.services.edit', $service)
            ->with('success', $service.' is using the shipped version of its form again.');
    }

    /**
     * The nested answer into the stored shape, with the mistakes an owner can
     * make on this page caught here rather than left for a customer to trip on.
     *
     * @param  array<string, mixed>  $posted
     * @return array<string, mixed>
     */
    private function compile(array $posted): array
    {
        // The rows keep the index the browser posted them under, so a message
        // about the third question lands on the third card even when a question
        // above it was deleted on this same save.
        $rows = collect($posted['fields'] ?? [])
            ->reject(fn ($row): bool => trim((string) ($row['name'] ?? '')) === '');

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'fields' => 'A service has to ask the customer for at least one thing.',
            ]);
        }

        $names = $rows->map(fn (array $row): string => $this->name($row['name']));
        if ($names->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'fields' => 'Two questions share the name "'
                    .$names->duplicates()->first().'" - every name must be its own.',
            ]);
        }

        // The answers each question can hold, as far as its own list says. A
        // branch written against an answer the question above it never gives
        // would simply never appear, which is the kind of mistake that survives
        // until a customer notices - so it is refused here instead.
        $answers = [];
        foreach ($rows as $index => $row) {
            $answers[$names[$index]] = array_values(array_filter(array_map(
                static fn ($option): string => trim((string) ($option['value'] ?? '')),
                (array) ($row['options'] ?? []),
            ), static fn (string $value): bool => $value !== ''));
        }

        $fields = [];
        foreach ($rows as $index => $row) {
            $name = $names[$index];
            $type = (string) ($row['type'] ?? 'text');

            if (!array_key_exists($type, self::TYPES)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.type" => 'That answer type is not one this platform can ask.',
                ]);
            }

            $branches = $this->branches($row, $names, $answers, $index);
            $options = $this->options($row, $index, $type, $branches === []);

            $field = [
                'name' => $name,
                'label' => trim((string) $row['label']),
                'type' => $type,
                'rules' => $this->rules($row['rules'] ?? '', $index, $type),
                // A dropdown that takes its menu from the answer above it has no
                // list of its own: keeping both would leave one of them dead.
                'options' => $branches === [] ? $options : [],
                'placeholder' => $this->text($row['placeholder'] ?? null),
                'hint' => $this->text($row['hint'] ?? null),
                'column' => trim((string) ($row['column'] ?? '')) !== '' ? trim((string) $row['column']) : trim((string) $row['label']),
                'accept' => $type === 'file' ? $this->text($row['accept'] ?? null) : null,
            ];

            // The menu a question takes from the answer above it has to be stored
            // with it, or the owner is promised a second dropdown that arrives at
            // the customer's page as an empty box.
            if ($branches !== []) {
                $field['options_by'] = $branches;
            }

            $pricing = $this->pricing($row, $this->choices($options, $branches), $index);
            if ($pricing !== []) {
                $field['price_by'] = $pricing;
            }

            $condition = $this->condition($row['show_if'] ?? null, $names, $answers, $index, 'show_if');
            if ($condition !== null) {
                $field['show_if'] = $condition;
            }

            $fields[] = $field;
        }

        $notes = [];
        foreach ((array) ($posted['notes'] ?? []) as $index => $note) {
            $text = trim((string) ($note['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $notes[] = [
                'text' => $text,
                'show_if' => $this->condition($note['show_if'] ?? null, $names, $answers, $index, 'notes'),
            ];
        }

        return ['fields' => $fields, 'notes' => $notes];
    }

    /**
     * The rows as this page must draw them again after a refusal: the same
     * reading of the posted form that compile() makes, but without its judgement.
     * A half-written question has to come back half-written, since it is the
     * unfinished one the owner needs to see.
     *
     * @param  array<string, mixed>  $posted
     * @return list<array<string, mixed>>
     */
    private function rowsForDisplay(array $posted): array
    {
        $rows = [];

        foreach ($posted as $row) {
            if (!is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }

            $choices = [];
            foreach ((array) ($row['options'] ?? []) as $option) {
                $value = trim((string) ($option['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = trim((string) ($option['label'] ?? ''));
                $choices[$value] = $label === '' ? $value : $label;
            }

            $branches = [];
            foreach ((array) ($row['options_by'] ?? []) as $branch) {
                $parent = trim((string) ($branch['field'] ?? ''));
                $value = trim((string) ($branch['value'] ?? ''));
                if ($parent === '' || $value === '') {
                    continue;
                }

                $menu = [];
                foreach (preg_split('/\r\n|\r|\n/', (string) ($branch['options'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    [$option, $label] = array_pad(explode('=', $line, 2), 2, '');
                    $menu[trim($option) === '' ? $line : trim($option)] = trim($label);
                }

                if ($menu !== []) {
                    $branches = ['field' => $parent, 'map' => ($branches['map'] ?? []) + [$value => $menu]];
                }
            }

            $prices = [];
            foreach ((array) ($row['prices'] ?? []) as $value => $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $prices[(string) $value] = $key;
                }
            }

            $rows[] = [
                'name' => strtolower(trim((string) $row['name'])),
                'label' => (string) ($row['label'] ?? ''),
                'type' => (string) ($row['type'] ?? 'text'),
                'rules' => array_values(array_filter(
                    array_map('trim', explode('|', (string) ($row['rules'] ?? ''))),
                    static fn (string $rule): bool => $rule !== '',
                )),
                'options' => $choices,
                'options_by' => $branches === [] ? null : $branches,
                'price_by' => ($prices ?? []) === [] ? null : ['map' => $prices, 'labels' => []],
                'show_if' => trim((string) ($row['show_if']['field'] ?? '')) === '' ? null : [
                    'field' => trim((string) $row['show_if']['field']),
                    'any' => array_values(array_filter(array_map(
                        'trim',
                        explode(',', (string) ($row['show_if']['any'] ?? '')),
                    ), static fn (string $value): bool => $value !== '')),
                ],
                'placeholder' => $this->text($row['placeholder'] ?? null),
                'hint' => $this->text($row['hint'] ?? null),
                'column' => $this->text($row['column'] ?? null),
                'accept' => $this->text($row['accept'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * A question's name is what the customer's answer is filed under, so it goes
     * into the database column and the agent's queue: lowercase, starting with a
     * letter, and never one of the names the platform reserves for itself.
     */
    private function name(mixed $raw): string
    {
        $name = strtolower(trim((string) $raw));

        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
            throw ValidationException::withMessages([
                'fields' => 'The question name "'.$name.'" must be one lowercase word, like nin or slip_type.',
            ]);
        }

        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw ValidationException::withMessages([
                'fields' => 'The name "'.$name.'" belongs to the form itself and cannot be used for a question.',
            ]);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function options(array $row, int $index, string $type, bool $mustOfferSomething): array
    {
        if ($type !== 'select') {
            return [];
        }

        $options = [];
        foreach ((array) ($row['options'] ?? []) as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));
            if ($value === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_\-]{1,60}$/', $value)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.options" => 'The choice "'.$value.'" can only hold letters, numbers, a dash or an underscore.',
                ]);
            }

            $options[$value] = $label !== '' ? $label : $value;
        }

        if ($options === [] && $mustOfferSomething) {
            throw ValidationException::withMessages([
                "fields.{$index}.options" => 'A dropdown needs at least one choice to offer.',
            ]);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function rules(mixed $raw, int $index, string $type): array
    {
        $rules = array_values(array_filter(
            array_map('trim', explode('|', (string) $raw)),
            static fn (string $rule): bool => $rule !== '',
        ));

        foreach ($rules as $rule) {
            $name = strtolower(strtok($rule, ':') ?: '');
            if (!in_array($name, self::ALLOWED_RULES, true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.rules" => 'The rule "'.$rule.'" is not one this platform will run. '
                        .'Allowed: '.implode(', ', self::ALLOWED_RULES).'.',
                ]);
            }
        }

        // A choice list decides its own "in" rule when the form is submitted, so
        // an owner who writes one by hand is not left with two lists to keep in
        // step. The same goes for a required tick.
        $rules = array_values(array_filter(
            $rules,
            static fn (string $rule): bool => !str_starts_with(strtolower($rule), 'in:'),
        ));

        if ($type === 'checkbox') {
            $rules[] = 'accepted';
        }

        return array_values(array_unique($rules));
    }

    /**
     * Which rate each choice selects. The number itself stays on the Settings
     * page under that key; this only records that the choice points at it.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function pricing(array $row, array $options, int $index): array
    {
        $map = [];
        $labels = [];

        foreach ((array) ($row['prices'] ?? []) as $value => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            if (!isset($options[$value])) {
                throw ValidationException::withMessages([
                    "fields.{$index}.prices" => 'The choice "'.$value.'" is not on this question any more.',
                ]);
            }

            if (!preg_match('/^price_[a-z0-9_]{3,69}$/', $key)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.prices" => 'A rate key must start with "price_" and hold letters, numbers or underscores only.',
                ]);
            }

            $map[$value] = $key;
            $labels[$key] ??= $options[$value];
        }

        return $map === [] ? [] : ['map' => $map, 'labels' => $labels];
    }

    /**
     * A branch: this question exists only when another one was answered one of
     * these ways.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $names
     * @param  array<string, list<string>>  $answers
     * @return array{field: string, any: list<string>}|null
     */
    private function condition(mixed $raw, $names, array $answers, int $index, string $list): ?array
    {
        $field = trim((string) ($raw['field'] ?? ''));
        if ($field === '') {
            return null;
        }

        if (!in_array($field, $names->all(), true)) {
            throw ValidationException::withMessages([
                "{$list}.{$index}.field" => 'There is no question named "'.$field.'" on this service.',
            ]);
        }

        $any = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($raw['any'] ?? ''))),
            static fn (string $value): bool => $value !== '',
        )));

        if ($any === []) {
            throw ValidationException::withMessages([
                "{$list}.{$index}.field" => 'Say which answer of "'.$field.'" brings this out - for example: yes, no.',
            ]);
        }

        // Asking for an answer the question above cannot give would leave this
        // question permanently hidden, so it is refused rather than shipped.
        if (($answers[$field] ?? []) !== []) {
            $unknown = array_values(array_diff($any, $answers[$field]));
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    "{$list}.{$index}.any" => '"'.$field.'" never answers "'.implode('", "', $unknown).'". '
                        .'Its answers are: '.implode(', ', $answers[$field]).'.',
                ]);
            }
        }

        return ['field' => $field, 'any' => $any];
    }

    /**
     * Menus whose choices come from the answer above them.
     *
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<int, string>  $names
     * @param  array<string, list<string>>  $answers
     * @return array<string, mixed>
     */
    private function branches(array $row, $names, array $answers, int $index): array
    {
        $field = '';
        $map = [];

        foreach ((array) ($row['options_by'] ?? []) as $branch) {
            $parent = trim((string) ($branch['field'] ?? ''));
            $value = trim((string) ($branch['value'] ?? ''));
            $lines = (string) ($branch['options'] ?? '');

            if ($parent === '' || $value === '') {
                continue;
            }

            if (!in_array($parent, $names->all(), true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.options_by" => 'There is no question named "'.$parent.'" on this service.',
                ]);
            }

            if ($field !== '' && $field !== $parent) {
                throw ValidationException::withMessages([
                    "fields.{$index}.options_by" => 'One question can follow one other question, not several.',
                ]);
            }

            if (($answers[$parent] ?? []) !== [] && !in_array($value, $answers[$parent], true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.options_by" => '"'.$parent.'" never answers "'.$value.'", so this menu would never appear. '
                        .'Its answers are: '.implode(', ', $answers[$parent]).'.',
                ]);
            }

            $choices = [];
            foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                [$option, $label] = array_pad(explode('=', $line, 2), 2, '');
                $option = trim($option);
                $label = trim($label);

                if ($option === '' || $label === '') {
                    throw ValidationException::withMessages([
                        "fields.{$index}.options_by" => 'Write each choice as value = Label, one per line. The line "'.$line.'" is not that.',
                    ]);
                }

                if (!preg_match('/^[A-Za-z0-9_\-]{1,60}$/', $option)) {
                    throw ValidationException::withMessages([
                        "fields.{$index}.options_by" => 'The choice "'.$option.'" can only hold letters, numbers, a dash or an underscore.',
                    ]);
                }

                $choices[$option] = $label;
            }

            if ($choices !== []) {
                $map[$value] = $choices;
                $field = $parent;
            }
        }

        if ($map === []) {
            return [];
        }

        return ['field' => $field, 'map' => $map];
    }

    /**
     * Every answer one question can hold: the choices written on it, plus the
     * choices each branch above it can bring out. A rate is keyed against this
     * set, so a price cannot be left pointing at an answer that no longer
     * exists - which is the one mistake that would quietly charge the wrong job.
     *
     * @param  array<string, string>  $own
     * @param  array<string, mixed>  $branches
     * @return array<string, string>
     */
    private function choices(array $own, array $branches): array
    {
        $values = $own;

        foreach ((array) ($branches['map'] ?? []) as $menu) {
            foreach ((array) $menu as $value => $label) {
                $values[(string) $value] = (string) $label;
            }
        }

        return $values;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
