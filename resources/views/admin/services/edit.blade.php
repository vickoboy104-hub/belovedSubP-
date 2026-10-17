<x-app-layout>
    @php
        // A cloned row is numbered past every saved one, and the slots inside it
        // are renumbered by position, so a question added on this page can never
        // land on an index the owner has already filled in.
        $next = 1000;

        $blankQuestion = [
            'name' => '', 'label' => '', 'type' => 'text', 'rules' => [],
            'options' => [], 'options_by' => null, 'price_by' => null, 'show_if' => null,
            'placeholder' => null, 'hint' => null, 'column' => null, 'accept' => null,
        ];
    @endphp

    <x-page-hero class="reference-shared-banner"
                 :title="'Questions for '.$definition['title']"
                 subtitle="What the customer is asked, in the order they are asked it, and the rate each answer selects." />

    <div class="reference-flow-page admin-light-page mx-auto max-w-5xl space-y-5">
        @if($errors->any())
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="list-disc ml-5 mt-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless($saved)
            <p class="rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
                This is the form it was shipped with. Saving writes your own version, which is then the only
                version customers see.
            </p>
        @endunless

        <form method="POST" action="{{ route('admin.services.update', $slug) }}" id="serviceFormEditor" class="space-y-5">
            @csrf

            <section class="rounded-3xl border border-gray-200 bg-white p-5">
                <h2 class="reference-section-title">This service</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="text-sm font-bold text-gray-800/80" for="svcTitle">Title</label>
                        <input id="svcTitle" type="text" name="title" maxlength="120"
                               value="{{ old('title', $definition['title']) }}" class="input-field mt-1">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80" for="svcGroup">Group</label>
                        <input id="svcGroup" type="text" name="group" maxlength="40"
                               value="{{ old('group', $definition['group'] ?? 'other') }}" class="input-field mt-1">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-bold text-gray-800/80" for="svcSummary">Line the customer reads on the services page</label>
                        <input id="svcSummary" type="text" name="summary" maxlength="400"
                               value="{{ old('summary', $definition['summary'] ?? '') }}" class="input-field mt-1">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80" for="svcIcon">Icon</label>
                        <input id="svcIcon" type="text" name="icon" maxlength="8"
                               value="{{ old('icon', $definition['icon'] ?? '') }}" class="input-field mt-1">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80" for="svcCta">Pay button wording</label>
                        <input id="svcCta" type="text" name="cta" maxlength="60"
                               value="{{ old('cta', $definition['cta'] ?? '') }}" class="input-field mt-1">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-bold text-gray-800/80" for="svcCost">The price line <span class="font-medium text-gray-500">— write ₦@{{price}} where the figure goes</span></label>
                        <input id="svcCost" type="text" name="cost_text" maxlength="200"
                               value="{{ old('cost_text', $definition['cost_text'] ?? '') }}" class="input-field mt-1">
                    </div>
                </div>
            </section>

            <section class="rounded-3xl border border-gray-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="reference-section-title">Questions</h2>
                    <button type="button" class="reference-quiet-button" data-add-question>Add a question</button>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    In the order the customer meets them. A question is only asked when the answer it waits for
                    has been given, and the deepest answer decides the rate.
                </p>

                <div class="mt-4 space-y-4" data-question-list>
                    @foreach($rows as $index => $question)
                        <x-services.question-row :index="$index" :question="$question" :others="$rows"
                                                 :types="$types" :rates="$rates" />
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-gray-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="reference-section-title">Notes under the form</h2>
                    <button type="button" class="reference-quiet-button" data-add-note>Add a note</button>
                </div>

                <div class="mt-3 space-y-3" data-note-list>
                    @foreach($editorNotes as $index => $note)
                        <div class="rounded-2xl border border-gray-200 p-3" data-note-row>
                            <textarea name="notes[{{ $index }}][text]" rows="2" maxlength="600"
                                      class="input-field w-full" placeholder="The personalized slip is removed a week after it is issued.">{{ $note['text'] }}</textarea>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div>
                                    <label class="text-xs font-bold text-gray-700">Show only after</label>
                                    <select name="notes[{{ $index }}][show_if][field]" class="input-field mt-1" data-parent-picker>
                                        <option value="">Always</option>
                                        @foreach($rows as $other)
                                            @if(($other['name'] ?? '') !== '')
                                                <option value="{{ $other['name'] }}"
                                                        @selected(($note['show_if']['field'] ?? '') === $other['name'])>{{ $other['name'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-gray-700">when the answer is</label>
                                    <input type="text" name="notes[{{ $index }}][show_if][any]" class="input-field mt-1"
                                           value="{{ implode(', ', (array) ($note['show_if']['any'] ?? [])) }}" placeholder="slip, change">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary">Save this form</button>
                <a href="{{ route('admin.services.index') }}" class="reference-quiet-button">Back to the list</a>
            </div>
        </form>

        @if($saved)
            <form method="POST" id="serviceFormResetForm"
                  action="{{ route('admin.services.reset', $slug) }}" class="inline"
                  data-confirm-sheet="confirmServiceFormReset"
                  data-confirm-details='{"Action":"Discard your saved form","Effect":"Customers go back to the version this service shipped with."}'>
                @csrf
                <button type="submit" class="btn-danger">
                    Use the shipped version again
                </button>
            </form>

            <x-confirm-modal id="confirmServiceFormReset" title="Use the shipped form again?"
                             confirmText="Discard my version" :danger="true" />
        @endif
    </div>

    {{-- New rows are built from these, so what an owner adds on this page posts
         exactly like what was already saved. --}}
    <template data-question-template>
        <x-services.question-row index="__I__" :question="$blankQuestion" :others="$rows"
                                 :types="$types" :rates="$rates" />
    </template>
    <template data-choice-template>
        <x-services.choice-row prefix="fields[__I__]" position="__C__" :rates="$rates" :price="null"
                               :choice="['value' => '', 'label' => '']" />
    </template>
    <template data-branch-template>
        <x-services.branch-row prefix="fields[__I__]" position="__C__" :branch="[]" :parents="$rows" />
    </template>
    <template data-note-template>
        <div class="rounded-2xl border border-gray-200 p-3" data-note-row>
            <textarea name="notes[__I__][text]" rows="2" maxlength="600"
                      class="input-field w-full" placeholder="A line the customer reads under the form."></textarea>
            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-bold text-gray-700">Show only after</label>
                    <select name="notes[__I__][show_if][field]" class="input-field mt-1" data-parent-picker>
                        <option value="">Always</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-700">when the answer is</label>
                    <input type="text" name="notes[__I__][show_if][any]" class="input-field mt-1" placeholder="slip, change">
                </div>
            </div>
        </div>
    </template>

    <script>
        (function () {
            const editor = document.getElementById('serviceFormEditor');
            if (!editor) return;

            const questions = editor.querySelector('[data-question-list]');
            const notes = editor.querySelector('[data-note-list]');
            let next = {{ $next }};

            /**
             * A cloned row is numbered once, on the way in: its question index is
             * written into every name it posts. The slot inside a choice or menu
             * list is left as a token, because that number moves each time a row
             * beside it is added or removed - only renumber() may settle it.
             */
            const clone = (selector, index) => {
                const holder = document.createElement('div');
                holder.innerHTML = document.querySelector(selector).innerHTML.replaceAll('__I__', String(index));
                return holder.firstElementChild;
            };

            const renumber = (list) => Array.from(list.children).forEach((row, slot) => {
                row.querySelectorAll('[data-indexed]').forEach((input) => {
                    input.name = input.getAttribute('data-indexed').replaceAll('__C__', String(slot));
                });
            });

            /** A question's own fields are numbered at the clone; its lists by position. */
            const renumberQuestion = (row) => {
                row.querySelectorAll('[data-choice-list], [data-branch-list]').forEach(renumber);
                row.querySelectorAll('[data-choice-row]').forEach(namePrice);
            };

            /**
             * The rate a choice selects is filed under that choice's own value, so
             * retyping the value moves the rate with it instead of stranding it on
             * an answer the form no longer offers.
             */
            function namePrice(row) {
                const value = row.querySelector('[data-choice-value]');
                const price = row.querySelector('[data-choice-price]');
                if (!value || !price) return;

                const prefix = value.getAttribute('data-indexed').replace('[options][__C__][value]', '');
                const answer = value.value.trim();
                if (answer === '') {
                    // A choice with no value yet has no answer to price, and an
                    // empty bracket makes PHP read the whole field as an array.
                    price.removeAttribute('name');
                    return;
                }
                price.name = prefix + '[prices][' + answer + ']';
            }

            const refreshOne = (picker) => {
                const keep = picker.value;
                const blank = picker.options[0]?.textContent ?? 'Always';
                const own = picker.closest('[data-question-row]')?.querySelector('[data-row-name]');

                picker.innerHTML = '';

                const none = document.createElement('option');
                none.value = '';
                none.textContent = blank;
                picker.appendChild(none);

                // Built from the names on the screen, so a question typed a second
                // ago can be depended on before this page has been saved.
                questions.querySelectorAll('[data-row-name]').forEach((input) => {
                    if (input === own) return;
                    const name = input.value.trim();
                    if (name === '') return;
                    const option = document.createElement('option');
                    option.value = name;
                    option.textContent = name;
                    picker.appendChild(option);
                });

                if (Array.from(picker.options).some((option) => option.value === keep)) {
                    picker.value = keep;
                }
            };

            const sync = () => {
                editor.querySelectorAll('[data-parent-picker]').forEach(refreshOne);
                questions.querySelectorAll('[data-question-row]').forEach((row) => {
                    const type = row.querySelector('[data-row-type]')?.value ?? 'text';
                    row.querySelector('[data-choice-only]')?.classList.toggle('hidden', type !== 'select');
                    row.querySelector('[data-file-only]')?.classList.toggle('hidden', type !== 'file');
                    renumberQuestion(row);
                });
            };

            const addTop = (button, list, selector) => button?.addEventListener('click', () => {
                list.appendChild(clone(selector, next++));
                sync();
            });

            addTop(editor.querySelector('[data-add-question]'), questions, '[data-question-template]');
            addTop(editor.querySelector('[data-add-note]'), notes, '[data-note-template]');

            editor.addEventListener('click', (event) => {
                if (event.target.closest('[data-add-choice]')) {
                    addChoice(event);
                    return;
                }
                if (event.target.closest('[data-add-branch]')) {
                    addBranch(event);
                    return;
                }
                if (event.target.closest('[data-remove-row]')) {
                    removeRow(event);
                }
            });

            const addChoice = (event) => {
                const row = event.target.closest('[data-question-row]');
                const list = row.querySelector('[data-choice-list]');
                list.appendChild(clone('[data-choice-template]', row.dataset.rowIndex));
                row.querySelector('[data-choice-only]').classList.remove('hidden');
                sync();
            };

            const addBranch = (event) => {
                const row = event.target.closest('[data-question-row]');
                const list = row.querySelector('[data-branch-list]');
                list.appendChild(clone('[data-branch-template]', row.dataset.rowIndex));
                row.querySelector('[data-choice-only]').classList.remove('hidden');
                sync();
            };

            const removeRow = (event) => {
                const row = event.target.closest('[data-choice-row], [data-branch-row], [data-question-row], [data-note-row]');
                if (!row) return;
                // A service that asks nothing cannot be submitted at all, so the
                // last question on the page has no way to leave it.
                if (row.matches('[data-question-row]') && questions.querySelectorAll('[data-question-row]').length === 1) {
                    return;
                }
                row.remove();
                sync();
            };

            editor.addEventListener('input', (event) => {
                if (event.target.matches('[data-choice-value]')) {
                    namePrice(event.target.closest('[data-choice-row]'));
                }
                if (event.target.matches('[data-row-name], [data-row-type]')) {
                    sync();
                }
            });

            editor.addEventListener('change', sync);

            // Every row already carries its own index, so nothing has to be
            // worked out here before the first sync.
            sync();
        })();
    </script>
</x-app-layout>
