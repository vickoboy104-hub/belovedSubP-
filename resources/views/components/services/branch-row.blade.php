@php
    // A menu that follows the answer above it: this row is one answer of the
    // parent question and the whole list that answer offers.
@endphp

<div class="rounded-xl bg-gray-50 p-2" data-branch-row>
    <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 flex-1">
            <label class="text-[11px] font-bold text-gray-600">after</label>
            <select name="{{ $prefix }}[options_by][{{ $position }}][field]" data-indexed="{{ $prefix }}[options_by][__C__][field]" class="input-field mt-1 text-xs" data-parent-picker>
                <option value="">Choose a question</option>
                @foreach($parents as $parent)
                    @if(($parent['name'] ?? '') !== '')
                        <option value="{{ $parent['name'] }}" @selected(($branch['field'] ?? '') === $parent['name'])>
                            {{ $parent['name'] }}
                        </option>
                    @endif
                @endforeach
            </select>
        </div>
        <div class="min-w-0 flex-1">
            <label class="text-[11px] font-bold text-gray-600">answers</label>
            <input type="text" name="{{ $prefix }}[options_by][{{ $position }}][value]" data-indexed="{{ $prefix }}[options_by][__C__][value]" maxlength="60"
                   class="input-field mt-1 font-mono text-xs"
                   value="{{ $branch['value'] ?? '' }}" placeholder="get_nin_slip">
        </div>
        <button type="button" class="text-xs font-bold text-red-600 underline" data-remove-row>Remove</button>
    </div>
    <label class="mt-2 block text-[11px] font-bold text-gray-600">then this menu offers</label>
    <textarea name="{{ $prefix }}[options_by][{{ $position }}][options]" data-indexed="{{ $prefix }}[options_by][__C__][options]" rows="2" maxlength="2000"
              class="input-field mt-1 w-full font-mono text-xs"
              placeholder="new_enrollment = New Enrollment">{{ $branch['options'] ?? '' }}</textarea>
</div>
