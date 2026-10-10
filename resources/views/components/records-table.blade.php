@props([
    'columns' => [],
    'rows' => [],
    'compact' => [],
    'total' => 0,
    'page' => 1,
    'pages' => 1,
    'perPage' => 25,
    'perPageOptions' => [10, 25, 50, 100],
    'search' => '',
    'searchPlaceholder' => 'Search',
    'emptyText' => 'No records yet.',
    'searchable' => true,
])

@php
    $columns = collect($columns)->values();
    $rows = collect($rows)->values();
    $compact = collect($compact)->isEmpty() ? $columns->pluck('key') : collect($compact)->values();
    $detailColumns = $columns->reject(fn ($column) => $compact->contains($column['key']));
    $pages = max(1, (int) $pages);
    $dash = '—';

    // A cell is either a plain value or a styled badge; anything blank prints the
    // provider's em dash rather than a zero or an empty box.
    $valueOf = function ($cell) use ($dash): string {
        $value = is_array($cell) ? ($cell['value'] ?? null) : $cell;

        return $value === null || $value === '' ? $dash : (string) $value;
    };
    $toneOf = fn ($cell): string => is_array($cell) ? (string) ($cell['tone'] ?? '') : '';
    $isStrong = fn ($cell): bool => is_array($cell) && !empty($cell['strong']);
@endphp

<div class="records-panel" x-data="{ open: null }">
    @if($searchable)
        <form method="GET" action="{{ url()->current() }}" class="records-toolbar">
            <div class="records-count" aria-live="polite">
                Total Record({{ number_format((int) $total) }})
                <span class="records-count-page">&mdash; page {{ (int) $page }} of {{ $pages }}</span>
            </div>

            <div class="records-tools">
                <input type="search"
                       name="q"
                       value="{{ $search }}"
                       placeholder="{{ $searchPlaceholder }}"
                       aria-label="{{ $searchPlaceholder }}"
                       class="records-search">

                <label class="records-size">
                    <span>Show</span>
                    <select name="per_page" class="records-select" x-on:change="$el.form.submit()">
                        @foreach($perPageOptions as $option)
                            <option value="{{ $option }}" @selected((int) $perPage === (int) $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    <span>entries</span>
                </label>

                <button type="submit" class="records-search-button">Search</button>
            </div>
        </form>
    @else
        <div class="records-toolbar records-toolbar-quiet">
            <div class="records-count">Total Record({{ number_format((int) $total) }})</div>
        </div>
    @endif

    {{-- Desktop reads as the provider's table; phones get the same rows behind
         the provider's "+" expand, so nothing wraps into a column of one letter. --}}
    <div class="records-table-wrap">
        <table class="records-table">
            <thead>
                <tr>
                    <th class="records-cell-toggle" aria-label="Expand"></th>
                    @foreach($columns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $index => $row)
                    @php $rowId = $row['id'] ?? $index; @endphp
                    <tr class="records-row">
                        <td class="records-cell-toggle">
                            <button type="button"
                                    class="records-expand"
                                    x-on:click="open = open === @js($rowId) ? null : @js($rowId)"
                                    :aria-expanded="String(open === @js($rowId))"
                                    x-text="open === @js($rowId) ? '−' : '+'">+</button>
                        </td>
                        @foreach($columns as $column)
                            @php $cell = $row['cells'][$column['key']] ?? null; @endphp
                            <td>
                                @if($toneOf($cell) !== '')
                                    <span class="records-badge records-badge-{{ $toneOf($cell) }}">{{ $valueOf($cell) }}</span>
                                @else
                                    <span @class(['records-cell-strong' => $isStrong($cell)])>{{ $valueOf($cell) }}</span>
                                    @if(is_array($cell) && !empty($cell['note']))
                                        <span class="records-note">{{ $cell['note'] }}</span>
                                    @endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr class="records-detail-row" x-show="open === @js($rowId)" x-cloak>
                        <td></td>
                        <td colspan="{{ $columns->count() }}">
                            <div class="records-detail">
                                @foreach($detailColumns as $column)
                                    <div class="records-detail-item">
                                        <span class="records-detail-label">{{ $column['label'] }}</span>
                                        <span class="records-detail-value">{{ $valueOf($row['cells'][$column['key']] ?? null) }}</span>
                                    </div>
                                @endforeach
                                @if(!empty($row['action']))
                                    <a href="{{ $row['action']['href'] }}" class="records-action">{{ $row['action']['label'] }}</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columns->count() + 1 }}" class="records-empty">{{ $emptyText }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="records-cards">
        @forelse($rows as $index => $row)
            @php $rowId = $row['id'] ?? $index; @endphp
            <article class="records-card">
                <div class="records-card-head">
                    <div class="records-card-lines">
                        @foreach($compact as $key)
                            @php $column = $columns->firstWhere('key', $key); @endphp
                            @continue($column === null)
                            @php $cell = $row['cells'][$key] ?? null; @endphp
                            <div class="records-card-line">
                                <span class="records-card-label">{{ $column['label'] }}</span>
                                @if($toneOf($cell) !== '')
                                    <span class="records-badge records-badge-{{ $toneOf($cell) }}">{{ $valueOf($cell) }}</span>
                                @else
                                    <span @class(['records-card-value', 'records-cell-strong' => $isStrong($cell)])>{{ $valueOf($cell) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <button type="button"
                            class="records-expand"
                            x-on:click="open = open === @js($rowId) ? null : @js($rowId)"
                            :aria-expanded="String(open === @js($rowId))"
                            x-text="open === @js($rowId) ? '−' : '+'">+</button>
                </div>

                <div class="records-detail" x-show="open === @js($rowId)" x-cloak>
                    @foreach($detailColumns as $column)
                        <div class="records-detail-item">
                            <span class="records-detail-label">{{ $column['label'] }}</span>
                            <span class="records-detail-value">{{ $valueOf($row['cells'][$column['key']] ?? null) }}</span>
                        </div>
                    @endforeach
                    @if(!empty($row['action']))
                        <a href="{{ $row['action']['href'] }}" class="records-action records-action-block">{{ $row['action']['label'] }}</a>
                    @endif
                </div>
            </article>
        @empty
            <div class="records-empty records-empty-card">{{ $emptyText }}</div>
        @endforelse
    </div>
</div>
