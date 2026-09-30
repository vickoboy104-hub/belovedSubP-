<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Notifications" subtitle="{{ (int) $unreadCount }} unread.">
        @if($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="reference-hero-action">Mark All Read</button>
            </form>
        @endif
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-4xl space-y-5">
        <section class="app-section p-4 sm:p-6">
            <div class="space-y-3">
                @forelse($notifications as $notification)
                    @php
                        $isUnread = is_null($notification->read_at);
                        $data = $notification->data ?? [];
                    @endphp
                    <article class="rounded-[22px] border p-4 {{ $isUnread ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-slate-50' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="font-bold text-sm text-slate-900">{{ $data['title'] ?? 'Notification' }}</div>
                            @if($isUnread)
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-[11px] font-bold text-slate-900">Unread</span>
                            @endif
                        </div>
                        <div class="mt-2 text-sm leading-6 text-slate-600">{{ $data['message'] ?? '' }}</div>
                        <div class="mt-2 text-xs text-slate-400">{{ optional($notification->created_at)->format('d M Y, h:ia') }}</div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No notifications yet.</div>
                @endforelse
            </div>

            @if(method_exists($notifications, 'links'))
                <div class="mt-5">
                    {{ $notifications->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
