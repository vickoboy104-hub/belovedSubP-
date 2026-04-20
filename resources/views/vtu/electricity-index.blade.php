<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-8">
        <section>
            <h1 class="app-page-title text-[2.1rem] sm:text-[2.6rem]">Electricity Bills</h1>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($services as $slug => $label)
                <a href="{{ route('vtu.electricity.service', $slug) }}" class="app-service-card block">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="app-service-title">{{ $label }}</div>
                            <div class="mt-2 text-sm text-slate-500">Open a dedicated page for {{ $label }} meter payment.</div>
                        </div>
                        <span class="app-service-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m9 6 6 6-6 6"></path>
                            </svg>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
