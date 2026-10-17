<x-app-layout>
    <x-page-hero class="reference-shared-banner"
                 title="Service Forms"
                 subtitle="Write what each service asks its customer, and which answer brings out the next question." />

    <div class="reference-flow-page admin-light-page mx-auto max-w-5xl space-y-5">
        <p class="rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
            A service starts from the form it was shipped with. The moment you save your own version here,
            that version is what customers see - and it keeps the rates you set on the
            <a href="{{ route('admin.settings') }}" class="underline font-bold text-gray-800">Settings page</a>,
            because a number should only ever live in one place.
            @if($edited !== [])
                {{ count($edited) }} of your services now hold an edited form.
            @else
                None of them has been edited yet.
            @endif
        </p>

        @foreach($groups as $group => $items)
            <section class="rounded-3xl border border-gray-200 bg-white p-5">
                <h2 class="reference-section-title">{{ ucfirst($group) }} services</h2>

                <div class="mt-3 space-y-3">
                    @foreach($items as $service)
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-extrabold text-gray-900">
                                        {{ $service['title'] }}
                                        <span class="ml-2 text-xs font-medium text-gray-500">{{ $service['slug'] }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $service['summary'] }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-[11px] font-bold">
                                        <span class="rounded-lg bg-gray-100 px-2 py-1 text-gray-700">
                                            {{ $service['questions'] }} question{{ $service['questions'] === 1 ? '' : 's' }}
                                        </span>
                                        @if($service['rates'] > 0)
                                            <span class="rounded-lg bg-gray-100 px-2 py-1 text-gray-700">
                                                {{ $service['rates'] }} rate{{ $service['rates'] === 1 ? '' : 's' }}
                                            </span>
                                        @endif
                                        <span class="rounded-lg px-2 py-1 {{ $service['edited'] ? 'bg-blue-50 text-blue-700' : 'bg-gray-50 text-gray-500' }}">
                                            {{ $service['edited'] ? 'Edited by you' : 'As shipped' }}
                                        </span>
                                        @unless($service['on_hub'])
                                            <span class="rounded-lg bg-amber-50 px-2 py-1 text-amber-700">Not on the hub</span>
                                        @endunless
                                    </div>
                                </div>

                                <div class="flex shrink-0 gap-2">
                                    @if($service['page'])
                                        <a href="{{ $service['page'] }}" class="reference-quiet-button" target="_blank" rel="noopener">See it</a>
                                    @endif
                                    <a href="{{ route('admin.services.edit', $service['slug']) }}" class="btn-primary">
                                        Edit questions
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-app-layout>
