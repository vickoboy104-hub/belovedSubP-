{{-- One pager for every list in the site. The framework's stock pagination builds a
     single non-wrapping row of fixed-size buttons, which on a 320px screen pushes the
     last page past the edge of a layout that hides horizontal overflow - the buttons
     are then unreachable rather than merely tight. These buttons wrap, and they are
     sized for a thumb instead of a mouse. Nothing here goes through __(), because on a
     case-insensitive filesystem a bare word like "Pagination" matches the framework's
     own lang/en/pagination.php and hands back the whole array. --}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        <p class="pager-count">
            Showing
            @if ($paginator->firstItem())
                <span class="pager-strong">{{ number_format($paginator->firstItem()) }}</span>–<span class="pager-strong">{{ number_format($paginator->lastItem()) }}</span>
            @else
                <span class="pager-strong">{{ number_format($paginator->count()) }}</span>
            @endif
            of <span class="pager-strong">{{ number_format($paginator->total()) }}</span> results
        </p>

        <div class="pager-buttons">
            @if ($paginator->onFirstPage())
                <span class="pager-button pager-button-off" aria-hidden="true">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pager-button">Previous</a>
            @endif

            @foreach (($elements ?? []) as $element)
                @if (is_string($element))
                    <span class="pager-button pager-button-off" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pager-button pager-button-now" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="pager-button" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pager-button">Next</a>
            @else
                <span class="pager-button pager-button-off" aria-hidden="true">Next</span>
            @endif
        </div>
    </nav>
@endif
