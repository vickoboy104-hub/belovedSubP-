@php
    $adminPages = [
        ['label' => 'Admin Dashboard', 'route' => 'admin.dashboard'],
        ['label' => 'Orders', 'route' => 'admin.orders'],
        ['label' => 'Manual Requests', 'route' => 'admin.manual-orders.index'],
        ['label' => 'Users', 'route' => 'admin.users'],
        ['label' => 'Wallet Transactions', 'route' => 'admin.wallet.transactions'],
        ['label' => 'Notifications', 'route' => 'admin.notifications.index'],
        ['label' => 'Support Chats', 'route' => 'admin.support.chats'],
        ['label' => 'Announcements', 'route' => 'admin.broadcast'],
        ['label' => 'Website Editor', 'route' => 'admin.website-editor'],
        ['label' => 'Settings', 'route' => 'admin.settings'],
    ];

    // The settings page keeps its Save card in the same corner, so the handle lifts.
    $lifted = request()->routeIs('admin.settings');
@endphp

<div id="adminQuickNav" class="admin-quick-nav {{ $lifted ? 'is-lifted' : '' }}">
    <button type="button" class="admin-quick-nav-toggle" aria-controls="adminQuickNavPanel" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <rect x="3" y="3" width="8" height="8" rx="2"></rect>
            <rect x="13" y="3" width="8" height="8" rx="2"></rect>
            <rect x="3" y="13" width="8" height="8" rx="2"></rect>
            <rect x="13" y="13" width="8" height="8" rx="2"></rect>
        </svg>
        <span>Navigate</span>
    </button>

    <div class="admin-quick-nav-backdrop" aria-hidden="true"></div>

    <aside id="adminQuickNavPanel" class="admin-quick-nav-panel" role="dialog" aria-modal="true" aria-label="Admin navigation">
        <div class="admin-quick-nav-head">
            <div class="admin-quick-nav-title">Admin sections</div>
            <button type="button" class="admin-quick-nav-close" aria-label="Close admin navigation">&times;</button>
        </div>

        <div class="admin-quick-nav-scroll">
            <div class="admin-quick-nav-heading">Pages</div>
            <nav class="admin-quick-nav-list">
                @foreach($adminPages as $page)
                    @if(Route::has($page['route']))
                        <a href="{{ route($page['route']) }}"
                           class="admin-quick-nav-link {{ request()->routeIs($page['route']) ? 'is-active' : '' }}">
                            <span>{{ $page['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </nav>

            <div id="adminQuickNavSections" class="{{ $lifted ? '' : 'hidden' }}">
                <div class="admin-quick-nav-heading">Settings sections</div>
                <nav class="admin-quick-nav-list" aria-label="Settings sections"></nav>
            </div>

            <a href="{{ route('dashboard') }}" class="admin-quick-nav-link admin-quick-nav-exit">
                <span>Back to site</span>
            </a>
        </div>
    </aside>
</div>
