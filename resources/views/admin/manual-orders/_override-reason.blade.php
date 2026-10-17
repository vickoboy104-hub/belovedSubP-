@php
    // Answering a request another agent is holding - or rewriting a result another
    // agent published - is allowed, but never silently: the reason is required by the
    // server and recorded against the request, so what looks like a finished job also
    // shows whose it was and who closed it.
    $over = $overrideOver ?? null;
@endphp
@if($over)
    <label class="block">
        <span class="text-sm font-bold text-slate-700">
            @if($over['waiting'])
                Why are you answering this over {{ $over['name'] }}? (required, kept off the customer's receipt)
            @else
                Why are you changing the result {{ $over['name'] }} published? (required, kept off the customer's receipt)
            @endif
        </span>
        <input type="text" name="override_reason" maxlength="500" required
               placeholder="e.g. {{ $over['name'] }} has been away since yesterday and the customer is overdue."
               value="{{ old('override_reason') }}" class="input-field mt-2" />
    </label>
@endif
