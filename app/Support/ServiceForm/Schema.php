<?php

namespace App\Support\ServiceForm;

/**
 * The rules a configurable service form runs by.
 *
 * A form is a tree of choices: the customer names the job, that answer decides
 * which question comes next, and the last answer fixes the fee. Both halves of
 * that tree have to be true at once - the browser shows the branch the customer
 * picked, and the server charges and validates the same branch rather than
 * whatever the request claims. This class is the single place that answers
 * "given these answers, what is on screen, what is required and what is the
 * price", so the two sides cannot drift.
 *
 * A field may therefore carry:
 *  - show_if      which other field's answer must be present for it to exist,
 *  - options_by   a dropdown whose own options depend on that answer,
 *  - price_by     a price key selected by one of its own options.
 *
 * Anything a customer cannot see must also be impossible to submit, so a field
 * outside the answered branch is dropped from validation and pruned from the
 * stored input here, before pricing, not after it.
 */
class Schema
{
    /**
     * Stands in for a single price while a page sells several rates and the
     * customer has not yet said which job they want. The server puts it on the
     * unanswered line and the browser adds it back when the choices are cleared,
     * so both sides use one sentence rather than two.
     */
    public const INVITATION = '— choose what you need done and the price for exactly that appears here.';

    /** @return array<string, mixed> */
    public static function normalize(array $definition): array
    {
        $definition['fields'] = array_values(array_map(
            static fn (array $field): array => self::normalizeField($field),
            (array) ($definition['fields'] ?? []),
        ));

        // A service that priced its options through the older single "tiers" block
        // keeps working: that block is one field's price_by and nothing more.
        if (($definition['tiers']['map'] ?? []) !== [] && ($definition['tiers']['field'] ?? '') !== '') {
            $field = (string) $definition['tiers']['field'];
            foreach ($definition['fields'] as $index => $candidate) {
                if ($candidate['name'] === $field && empty($candidate['price_by'])) {
                    $definition['fields'][$index]['price_by'] = [
                        'map' => (array) $definition['tiers']['map'],
                        'labels' => (array) ($definition['tiers']['labels'] ?? []),
                    ];
                }
            }
        }

        $definition['notes'] = self::normalizeNotes((array) ($definition['notes'] ?? []), (array) ($definition['notices'] ?? []));
        // The merge is a move, not a copy. A definition is normalized once when
        // the catalogue is read and again by the page that renders it, so leaving
        // the old key standing printed every provider warning twice.
        unset($definition['notices']);

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normalizeField(array $field): array
    {
        $field['name'] = (string) ($field['name'] ?? '');
        $field['label'] = (string) ($field['label'] ?? '');
        $field['type'] = (string) ($field['type'] ?? 'text');
        $field['rules'] = array_values((array) ($field['rules'] ?? []));
        $field['options'] = (array) ($field['options'] ?? []);
        $field['hint'] = $field['hint'] ?? null;
        $field['placeholder'] = $field['placeholder'] ?? null;
        $field['accept'] = $field['accept'] ?? null;
        $field['column'] = (string) ($field['column'] ?? $field['label']);
        $field['show_if'] = self::normalizeCondition($field['show_if'] ?? null);
        $field['options_by'] = self::normalizeDependencies($field['options_by'] ?? null);
        $field['price_by'] = self::normalizePricing($field['price_by'] ?? null);
        $field['required'] = self::isRequired($field['rules']);

        return $field;
    }

    /**
     * @return array{field: string, any: list<string>}|null
     */
    private static function normalizeCondition(mixed $condition): ?array
    {
        if (!is_array($condition)) {
            return null;
        }

        $field = (string) ($condition['field'] ?? '');
        if ($field === '') {
            return null;
        }

        $values = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($condition['any'] ?? (array) ($condition['in'] ?? [$condition['value'] ?? '']))
        ));

        return $values === [] ? null : ['field' => $field, 'any' => $values];
    }

    /**
     * @return array<string, array{field: string, map: array<string, array<string, string>>}>|null
     */
    private static function normalizeDependencies(mixed $dependencies): ?array
    {
        if (!is_array($dependencies)) {
            return null;
        }

        $field = (string) ($dependencies['field'] ?? '');
        $map = [];
        foreach ((array) ($dependencies['map'] ?? []) as $value => $options) {
            $map[(string) $value] = array_combine(
                array_map(static fn (string $key): string => (string) $key, array_keys((array) $options)),
                array_map(static fn (mixed $label): string => (string) $label, array_values((array) $options)),
            );
        }

        return $field === '' || $map === [] ? null : ['field' => $field, 'map' => $map];
    }

    /**
     * @return array{map: array<string, string>, labels: array<string, string>}|null
     */
    private static function normalizePricing(mixed $pricing): ?array
    {
        if (!is_array($pricing)) {
            return null;
        }

        $map = [];
        foreach ((array) ($pricing['map'] ?? []) as $option => $key) {
            $map[(string) $option] = (string) $key;
        }

        return $map === [] ? null : [
            'map' => $map,
            'labels' => array_map(
                static fn (mixed $label): string => (string) $label,
                (array) ($pricing['labels'] ?? [])
            ),
        ];
    }

    /**
     * Notices the provider shows inside the form rather than above it - the
     * warning that appears only once the customer has chosen the job that earns
     * it. The older flat `notices` list still renders, always visible.
     *
     * @return list<array{text: string, show_if: array{field: string, any: list<string>}|null}>
     */
    private static function normalizeNotes(array $notes, array $legacy): array
    {
        $out = [];
        foreach ($legacy as $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[] = ['text' => trim($text), 'show_if' => null];
            }
        }

        foreach ($notes as $note) {
            if (!is_array($note)) {
                continue;
            }
            $text = trim((string) ($note['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = ['text' => $text, 'show_if' => self::normalizeCondition($note['show_if'] ?? null)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function fields(array $definition): array
    {
        return (array) ($definition['fields'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public static function field(array $definition, string $name): ?array
    {
        foreach (self::fields($definition) as $field) {
            if (($field['name'] ?? '') === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The answers a dropdown may actually hold right now, which is the customer's
     * current choice for the field it depends on - not every option the schema
     * happens to know about.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function optionsFor(array $field, array $input): array
    {
        $dependencies = $field['options_by'] ?? null;
        if (!is_array($dependencies)) {
            return (array) ($field['options'] ?? []);
        }

        $parent = self::answer($input, $dependencies['field']);
        if ($parent === '' || !array_key_exists($parent, $dependencies['map'])) {
            // An unanswered parent has no child options yet. Showing the first
            // branch's list would suggest a choice that was never made.
            return [];
        }

        return $dependencies['map'][$parent];
    }

    /**
     * A field is on the screen only when the answer it hangs on has been given,
     * is one the question it belongs to can actually give, and that question is
     * itself on the screen. Without the last part a hidden field could keep an
     * old answer and leave a deeper question showing after the customer had
     * changed their mind two questions above it.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $input
     */
    public static function isVisible(array $definition, array $field, array $input, int $depth = 0): bool
    {
        $condition = $field['show_if'] ?? null;
        if (!is_array($condition)) {
            return true;
        }

        // A branch that nests deeper than twelve levels is a cycle or a mistake,
        // and either way no customer should be shown it.
        if ($depth > 12) {
            return false;
        }

        $value = self::answer($input, $condition['field']);
        if ($value === '') {
            return false;
        }

        $parent = self::field($definition, $condition['field']);
        if (!is_array($parent) || !self::isVisible($definition, $parent, $input, $depth + 1)) {
            return false;
        }

        // The branch only holds if the answer it hangs on is itself an answer the
        // parent can give; a stray value cannot reveal a hidden field.
        $choices = self::optionsFor($parent, $input);
        if ($choices !== [] && !array_key_exists($value, $choices)) {
            return false;
        }

        return in_array($value, $condition['any'], true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public static function visibleFields(array $definition, array $input): array
    {
        return array_values(array_filter(
            self::fields($definition),
            static fn (array $field): bool => self::isVisible($definition, $field, $input),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function visibleFieldNames(array $definition, array $input): array
    {
        return array_map(
            static fn (array $field): string => (string) $field['name'],
            self::visibleFields($definition, $input),
        );
    }

    /**
     * Everything the customer sent that belongs to the branch they are on. A
     * field from another branch is dropped here, so it can never be stored,
     * shown to an agent or used to pick a price.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prune(array $definition, array $input): array
    {
        $allowed = self::visibleFieldNames($definition, $input);
        $allowed[] = 'notes';
        $allowed[] = 'consent_agreed';

        return array_intersect_key($input, array_flip($allowed));
    }

    /**
     * Validation rules for the answered branch only.
     *
     * A dropdown's `in:` list is rebuilt from the options that are actually live
     * for the current answers, so a value copied from a different branch, or a
     * value the owner has since removed, is refused instead of accepted and
     * mispriced.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(array $definition, array $input): array
    {
        $rules = [];

        foreach (self::visibleFields($definition, $input) as $field) {
            $fieldRules = array_values(array_filter(
                (array) $field['rules'],
                static fn (string $rule): bool => !str_starts_with($rule, 'in:'),
            ));

            if (($field['type'] ?? 'text') === 'select') {
                $choices = array_keys(self::optionsFor($field, $input));
                if ($choices === []) {
                    // No legal value exists yet for this field, so a submission
                    // carrying one is a forged branch, not a customer's answer.
                    $fieldRules[] = 'in:__none__';
                } else {
                    $fieldRules[] = 'in:'.implode(',', $choices);
                }
            }

            $rules[$field['name']] = $fieldRules === [] ? ['nullable'] : $fieldRules;
        }

        return $rules;
    }

    /**
     * The settings key the answered branch is charged at. Later fields win, so
     * the deepest question - the one the customer answers last - is the one that
     * sets the price, which is how the fee on screen and the fee taken stay the
     * same number.
     *
     * @param  array<string, mixed>  $input
     */
    public static function priceKey(string $slug, array $definition, array $input): string
    {
        $key = (string) ($definition['price_key'] ?? 'price_manual_'.$slug);

        foreach (self::fields($definition) as $field) {
            $pricing = $field['price_by'] ?? null;
            if (!is_array($pricing)) {
                continue;
            }

            $value = self::answer($input, $field['name']);
            if ($value !== '' && isset($pricing['map'][$value])
                && array_key_exists($value, self::optionsFor($field, $input))) {
                $key = $pricing['map'][$value];
            }
        }

        return $key;
    }

    /**
     * Which settings key each selectable option is charged at, grouped by the
     * field that offers it. Grouping by field rather than by option value matters
     * as soon as a form has two questions that use the same word for different
     * jobs - "name" in one dropdown must never borrow the other's price.
     *
     * @return array<string, array<string, string>> field => option => key
     */
    public static function priceMap(array $definition): array
    {
        $map = [];
        foreach (self::fields($definition) as $field) {
            $pricing = $field['price_by'] ?? null;
            if (!is_array($pricing)) {
                continue;
            }
            $map[(string) $field['name']] = $pricing['map'];
        }

        return $map;
    }

    /**
     * Every price this service can charge, keyed by the option that leads to it,
     * so the form can name the fee the moment a choice fixes it and the settings
     * page can offer an input for each rate without being rewritten.
     *
     * @return array<string, array{key: string, label: string}>
     */
    public static function priceOptions(array $definition): array
    {
        $options = [];
        foreach (self::fields($definition) as $field) {
            $pricing = $field['price_by'] ?? null;
            if (!is_array($pricing)) {
                continue;
            }

            $names = (array) ($field['options'] ?? []);
            foreach ((array) ($field['options_by']['map'] ?? []) as $branch) {
                $names += (array) $branch;
            }

            foreach ($pricing['map'] as $value => $key) {
                $value = (string) $value;
                $options[$value] = [
                    'key' => (string) $key,
                    // The owner's name for the rate wins over the option's own
                    // label, because several options can share one named rate.
                    'label' => (string) ($pricing['labels'][$key] ?? $names[$value] ?? $value),
                ];
            }
        }

        return $options;
    }

    /**
     * The words an stored answer is known by, wherever that option lives - a
     * dependent menu's choices are filed under the branch they belong to, so the
     * history table has to look through all of them to name what was ordered
     * rather than printing the internal value back at the customer.
     */
    public static function labelFor(array $definition, string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $definition = self::normalize($definition);
        $spec = self::field($definition, $field) ?? [];

        if (isset($spec['options'][$value])) {
            return (string) $spec['options'][$value];
        }

        foreach ((array) ($spec['options_by']['map'] ?? []) as $branch) {
            if (isset($branch[$value])) {
                return (string) $branch[$value];
            }
        }

        return $value;
    }

    /**
     * The notes that belong to the answers given, which is how a warning tied to
     * one choice appears under that choice and disappears when it is changed.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function notesFor(array $definition, array $input): array
    {
        $out = [];
        foreach ((array) ($definition['notes'] ?? []) as $note) {
            $condition = $note['show_if'] ?? null;
            if (is_array($condition) && !self::isVisible($definition, ['show_if' => $condition], $input)) {
                continue;
            }
            $out[] = (string) $note['text'];
        }

        return $out;
    }

    /**
     * The branch map the browser is handed, in the field order the server walks.
     * Prices travel as kobo for display only - the charge is always recomputed
     * here from the stored schema, never read back from the request.
     *
     * @param  array<string, array<string, int>>  $branchPrices  field => option => kobo
     * @return array<string, mixed>
     */
    public static function clientConfig(array $definition, array $branchPrices, int $basePriceKobo): array
    {
        $span = self::priceSpan($branchPrices);
        $fields = [];
        foreach (self::fields($definition) as $field) {
            $name = (string) $field['name'];

            $fields[] = [
                'name' => $name,
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'options' => self::optionList($field['options']),
                'optionsBy' => is_array($field['options_by'] ?? null) ? [
                    'field' => $field['options_by']['field'],
                    'map' => array_map(
                        static fn (array $options): array => self::optionList($options),
                        $field['options_by']['map'],
                    ),
                ] : null,
                'showIf' => $field['show_if'],
                'priceFor' => (object) ($branchPrices[$name] ?? []),
            ];
        }

        return [
            'fields' => $fields,
            'basePrice' => $basePriceKobo,
            'span' => $span,
            'floorPrice' => self::priceFloor($branchPrices, $basePriceKobo),
            'invitation' => $span === null ? null : self::INVITATION,
            'notes' => array_map(
                static fn (array $note): array => ['text' => $note['text'], 'showIf' => $note['show_if']],
                (array) ($definition['notes'] ?? []),
            ),
        ];
    }

    /**
     * The least this page could ever charge for the job it is showing.
     *
     * A service's base rate is the price of the whole service, while a choice
     * inside it has a rate of its own, and the two need not agree. Until the
     * customer has picked one, the honest figure is the lowest rate any of the
     * choices carries - never the base, which on a tiered service is a number
     * nothing on the page will charge. Only a page with no priced choices at
     * all has the base as its real price.
     *
     * @param  array<string, array<string, int>>  $branchPrices
     */
    public static function priceFloor(array $branchPrices, int $basePriceKobo): int
    {
        $lowest = null;

        foreach ($branchPrices as $options) {
            foreach ((array) $options as $kobo) {
                $kobo = (int) $kobo;
                if ($lowest === null || $kobo < $lowest) {
                    $lowest = $kobo;
                }
            }
        }

        return $lowest ?? $basePriceKobo;
    }

    /**
     * The whole range a page of choices can cost, or null when every choice
     * costs the same. A page with a range cannot name one figure before the
     * customer says which job they want, and a page without one has nothing to
     * hold back - so both halves of the price line follow from this single fact.
     *
     * @param  array<string, array<string, int>>  $branchPrices
     * @return array{0: int, 1: int}|null
     */
    public static function priceSpan(array $branchPrices): ?array
    {
        $rates = [];

        foreach ($branchPrices as $options) {
            foreach ((array) $options as $kobo) {
                $rates[(int) $kobo] = true;
            }
        }

        if (count($rates) < 2) {
            return null;
        }

        $rates = array_keys($rates);
        sort($rates);

        return [$rates[0], end($rates)];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function optionList(array $options): array
    {
        return array_map(
            static fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
            array_map(static fn (mixed $key): string => (string) $key, array_keys($options)),
            array_map(static fn (mixed $label): string => (string) $label, array_values($options)),
        );
    }

    /** @param  list<string>  $rules */
    private static function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === 'required' || str_starts_with((string) $rule, 'required')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One answer, as text. An uploaded file arriving in the same request as the
     * choices is not an answer to anything and reads as an empty string, so it
     * can never be mistaken for a branch.
     */
    private static function answer(array $input, string $field): string
    {
        $value = $input[$field] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The document questions on the branch the customer actually answered, each
     * with the flag that says whether they had to answer it. Whether the answer
     * is required decides what may happen when the file cannot be kept: a
     * mandatory document that did not arrive makes the request unusable, so the
     * money must not move.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{name: string, required: bool}>
     */
    public static function uploadFields(array $definition, array $input): array
    {
        return array_values(array_map(
            static fn (array $field): array => [
                'name' => (string) $field['name'],
                'required' => (bool) ($field['required'] ?? false),
            ],
            array_filter(
                self::visibleFields($definition, $input),
                static fn (array $field): bool => ($field['type'] ?? '') === 'file',
            ),
        ));
    }

    /**
     * The fields on the answered branch that hold a document rather than text.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public static function fileFields(array $definition, array $input): array
    {
        return array_column(self::uploadFields($definition, $input), 'name');
    }
}
