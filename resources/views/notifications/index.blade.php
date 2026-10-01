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
                    <article class="app-note-card {{ $isUnread ? 'is-unread' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="font-bold text-sm text-slate-900">{{ $data['title'] ?? 'Notification' }}</div>
                            @if($isUnread)
                                <span class="app-flag app-flag-unread">Unread</span>
                            @endif
                        </div>
                        <div class="mt-2 text-sm leading-6 text-slate-600">{{ $data['message'] ?? '' }}</div>
                        <div class="mt-2 text-xs opacity-70">{{ optional($notification->created_at)->format('d M Y, h:ia') }}</div>
                    </article>
                @empty
                    <div class="app-note-card text-sm opacity-70">No notifications yet.</div>
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
