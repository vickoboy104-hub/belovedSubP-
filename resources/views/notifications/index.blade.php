<x-app-layout>
    <div class="max-w-4xl mx-auto w-full px-4 sm:px-0 space-y-5">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">Notifications</h2>
                    <p class="text-sm text-gray-600 dark:text-white/60 mt-1">
                        You have {{ (int) $unreadCount }} unread messages.
                    </p>
                </div>
                @if($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button class="px-4 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 text-white text-sm font-bold">
                            Mark All Read
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="rounded-3xl p-4 sm:p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <div class="space-y-2">
                @forelse($notifications as $notification)
                    @php
                        $isUnread = is_null($notification->read_at);
                        $data = $notification->data ?? [];
                    @endphp
                    <div class="rounded-2xl p-3 border {{ $isUnread ? 'border-orange-400/40 bg-orange-500/10' : 'border-gray-200 dark:border-white/10 bg-black/5 dark:bg-black/20' }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="font-bold text-sm">{{ $data['title'] ?? 'Notification' }}</div>
                            @if($isUnread)
                                <span class="text-[11px] px-2 py-1 rounded-full bg-orange-500/20 text-orange-200 font-bold">Unread</span>
                            @endif
                        </div>
                        <div class="text-sm text-gray-700 dark:text-white/70 mt-1">{{ $data['message'] ?? '' }}</div>
                        <div class="text-xs text-gray-500 dark:text-white/50 mt-1">
                            {{ optional($notification->created_at)->format('d M Y, h:ia') }}
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 dark:text-white/60">No notifications yet.</div>
                @endforelse
            </div>

            @if(method_exists($notifications, 'links'))
                <div class="mt-5">
                    {{ $notifications->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

