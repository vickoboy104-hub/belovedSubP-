<x-app-layout>
    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">Education Services</h1>
            <p class="app-page-subtitle">Select the exam body you want to purchase a PIN for.</p>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach($services as $slug => $label)
                <a href="{{ route('vtu.exam.service', $slug) }}" class="app-service-card block">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="app-service-title">{{ $label }}</div>
                            <div class="mt-2 text-sm text-slate-500">Choose this service and continue.</div>
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
