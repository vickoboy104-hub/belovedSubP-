@php
    // The index is written into every name this row posts, and it is also the
    // token a cloned row is renamed by, so it stays as it arrives.
    $prefix = 'fields['.$index.']';
    $name = (string) ($question['name'] ?? '');
    $type = (string) ($question['type'] ?? 'text');

    // Saved choices are held as value => label; a value goes into the answer the
    // platform stores and the label is what the customer reads.
    $choices = [];
    foreach ((array) ($question['options'] ?? []) as $value => $option) {
        if (is_array($option)) {
            $value = $option['value'] ?? '';
            $option = $option['label'] ?? '';
        }
        if (trim((string) $value) === '') {
            continue;
        }
        $choices[] = ['value' => (string) $value, 'label' => (string) $option];
    }

    $priceMap = (array) ($question['price_by']['map'] ?? []);
    $branchField = (string) ($question['options_by']['field'] ?? '');
    $branches = [];
    foreach ((array) ($question['options_by']['map'] ?? []) as $answer => $menu) {
        $lines = [];
        foreach ((array) $menu as $value => $label) {
            $lines[] = $value.' = '.$label;
        }
        $branches[] = ['field' => $branchField, 'value' => (string) $answer, 'options' => implode("\n", $lines)];
    }
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-3" data-question-row data-row-index="{{ $index }}">
    <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 flex-1">
            <label class="text-xs font-bold text-gray-700">Name the answer is filed under</label>
            <input type="text" name="{{ $prefix }}[name]" maxlength="40" data-row-name
                   class="input-field mt-1 font-mono"
                   value="{{ $name }}" placeholder="nin">
        </div>
        <div>
            <label class="text-xs font-bold text-gray-700">Type</label>
            <select name="{{ $prefix }}[type]" class="input-field mt-1" data-row-type>
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="text-xs font-bold text-red-600 underline" data-remove-row>
            Remove
        </button>
    </div>

    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="text-xs font-bold text-gray-700">Question the customer reads</label>
            <input type="text" name="{{ $prefix }}[label]" maxlength="160" class="input-field mt-1"
                   value="{{ $question['label'] ?? '' }}" placeholder="Enter the NIN Number">
        </div>
        <div>
            <label class="text-xs font-bold text-gray-700">Column heading on the admin's page</label>
            <input type="text" name="{{ $prefix }}[column]" maxlength="60" class="input-field mt-1"
                   value="{{ $question['column'] ?? '' }}" placeholder="NIN">
        </div>
    </div>

    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="text-xs font-bold text-gray-700">Rules <span class="font-medium text-gray-500">— required|digits:11</span></label>
            <input type="text" name="{{ $prefix }}[rules]" maxlength="600" class="input-field mt-1 font-mono"
                   value="{{ implode('|', array_map('strval', (array) ($question['rules'] ?? []))) }}"
                   placeholder="required|string|max:255">
        </div>
        <div>
            <label class="text-xs font-bold text-gray-700">Example shown inside the box</label>
            <input type="text" name="{{ $prefix }}[placeholder]" maxlength="160" class="input-field mt-1"
                   value="{{ $question['placeholder'] ?? '' }}" placeholder="Enter NIN e.g 74227342856">
        </div>
    </div>

    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <label class="text-xs font-bold text-gray-700">Help line under the box</label>
            <input type="text" name="{{ $prefix }}[hint]" maxlength="400" class="input-field mt-1"
                   value="{{ $question['hint'] ?? '' }}" placeholder="The 15-character ID on your enrolment slip.">
        </div>
        <div data-file-only @class(['hidden' => $type !== 'file'])>
            <label class="text-xs font-bold text-gray-700">Upload types allowed</label>
            <input type="text" name="{{ $prefix }}[accept]" maxlength="120" class="input-field mt-1 font-mono"
                   value="{{ $question['accept'] ?? '' }}" placeholder=".pdf,.jpg,.png">
        </div>
    </div>

    <div class="mt-2">
        <label class="text-xs font-bold text-gray-700">Show only after</label>
        <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <select name="{{ $prefix }}[show_if][field]" class="input-field" data-parent-picker>
                <option value="">Always asked</option>
                @foreach($others as $other)
                    @if(($other['name'] ?? '') !== '' && ($other['name'] ?? '') !== $name)
                        <option value="{{ $other['name'] }}"
                                @selected(($question['show_if']['field'] ?? '') === $other['name'])>{{ $other['name'] }}</option>
                    @endif
                @endforeach
            </select>
            <input type="text" name="{{ $prefix }}[show_if][any]" maxlength="400" class="input-field"
                   value="{{ implode(', ', array_map('strval', (array) ($question['show_if']['any'] ?? []))) }}"
                   placeholder="yes, no">
        </div>
    </div>

    <div data-choice-only @class(['hidden' => $type !== 'select'])>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <label class="text-xs font-bold text-gray-700">Choices the customer picks from</label>
            <button type="button" class="text-xs font-bold text-blue-700 underline" data-add-choice>
                Add a choice
            </button>
        </div>

        <div class="mt-1 space-y-2" data-choice-list>
            @foreach(array_merge($choices, $choices === [] ? [['value' => '', 'label' => '']] : []) as $position => $choice)
                <x-services.choice-row :prefix="$prefix" :position="$position" :choice="$choice" :rates="$rates"
                                       :price="$priceMap[(string) ($choice['value'] ?? '')] ?? null" />
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <label class="text-xs font-bold text-gray-700">
                Or take the list from the answer above
                <span class="font-medium text-gray-500">— one choice per line, written value = Label</span>
            </label>
            <button type="button" class="text-xs font-bold text-blue-700 underline" data-add-branch>
                Add a menu
            </button>
        </div>

        <div class="mt-1 space-y-2" data-branch-list>
            @foreach(array_merge($branches, $branches === [] ? [[]] : []) as $position => $branch)
                <x-services.branch-row :prefix="$prefix" :position="$position" :branch="$branch" :parents="$others" />
            @endforeach
        </div>
    </div>
</div>
