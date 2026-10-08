{{-- The customer's alert shade. It asks for its own count because a deposit is
     banked by whoever finds it first - Flutterwave's webhook, or the wallet page
     being opened - and the person watching their balance should not have to
     reload to learn that money arrived. --}}
<div x-data="notificationBell({{ Js::from(route('notifications.feed')) }}, {{ Js::from(route('notifications.read', ['notificationId' => '__NOTIFICATION_ID__'])) }})"
     class="app-bell relative">
    <button type="button"
            class="mobile-topbar-icon"
            @click="toggle()"
            :aria-expanded="open"
            :aria-label="unread > 0 ? 'Alerts, ' + unread + ' unread' : 'Alerts'">
        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"></path>
            <path d="M10.5 19a2.2 2.2 0 0 0 3 0"></path>
        </svg>
        <span x-show="unread > 0" x-cloak x-text="badge" class="app-bell-badge"></span>
    </button>

    {{-- On a phone the tray cannot hang off the bell: the header is 64px tall and
         the list is far taller than the strip of screen left below it, so an
         anchored panel always runs off the bottom. The layer centres it instead,
         and only gives the desktop its anchored dropdown back. --}}
    <div x-cloak x-show="open"
         @click.outside="open = false"
         @keydown.escape.window="open = false"
         x-transition:enter="transition duration-200 ease-out"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition duration-150 ease-in"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="app-bell-layer">
        <div class="app-glass-card app-bell-panel p-2 text-slate-800">
            <div class="flex items-center justify-between gap-3 px-1 pb-2">
                <span class="text-sm font-extrabold">Alerts</span>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-xs font-bold underline opacity-75">Mark all read</button>
                </form>
            </div>

            <div class="app-bell-list">
                <template x-for="item in items" :key="item.id">
                    <a :href="item.url"
                         @click.prevent="follow(item)"
                         class="app-bell-item"
                         :class="{ 'is-unread': item.unread }">
                        <span class="app-bell-title">
                            <span x-text="item.title"></span>
                            <span class="app-bell-amount" x-show="item.amount" x-text="item.amount"></span>
                        </span>
                        <span class="app-bell-text" x-text="item.message"></span>
                        <span class="app-bell-meta">
                            <span x-text="item.at"></span>
                            <span x-show="item.unread" class="font-bold">new</span>
                        </span>
                    </a>
                </template>

                <p x-show="!loading && items.length === 0" class="app-bell-empty">
                    Nothing yet. Payments, order results and replies from support arrive here.
                </p>
                <p x-show="loading && items.length === 0" class="app-bell-empty">Checking your alerts…</p>
            </div>

            <a href="{{ route('notifications.index') }}" class="app-bell-all">View all alerts →</a>
        </div>
    </div>
</div>
