@php
    // Only rendered on admin routes, so the queue count is cheap to read here and
    // the admin sees pending volume without leaving the page they are on.
    $waitingManualRequests = app(\App\Services\ManualFulfilmentService::class)->waitingCount();

    // Transfers the site can see but cannot attribute. Nothing gets paid until a
    // person reads them, so they have to be impossible to walk past.
    $heldDeposits = \App\Models\FlutterwaveHeldDeposit::query()->where('status', 'held')->count();

    // Each link names the permission that opens its page, so the drawer never shows
    // an administrator a door that will refuse them. The refusal is real either way -
    // this only keeps the menu honest.
    $adminPages = [
        ['label' => 'Admin Dashboard', 'route' => 'admin.dashboard'],
        ['label' => 'Orders', 'route' => 'admin.orders', 'can' => ['orders.view']],
        ['label' => 'Manual Requests', 'route' => 'admin.manual-orders.index', 'badge' => $waitingManualRequests, 'can' => ['orders.manual.view']],
        ['label' => 'Users', 'route' => 'admin.users', 'can' => ['customers.view']],
        ['label' => 'Wallet Transactions', 'route' => 'admin.wallet.transactions', 'can' => ['wallet.transactions.view']],
        ['label' => 'Wallet Statistics', 'route' => 'admin.wallet-stats', 'can' => ['wallet.stats.view']],
        ['label' => 'Held Deposits', 'route' => 'admin.wallet.deposits.held', 'badge' => $heldDeposits, 'can' => ['wallet.held.view']],
        ['label' => 'Settlement Declarations', 'route' => 'admin.settlement-links.index', 'can' => ['wallet.settlement.declare']],
        ['label' => 'Notifications', 'route' => 'admin.notifications.index'],
        ['label' => 'Support Chats', 'route' => 'admin.support.chats', 'can' => ['support.manage']],
        ['label' => 'Announcements', 'route' => 'admin.broadcast', 'can' => ['broadcasts.send']],
        ['label' => 'Website Editor', 'route' => 'admin.website-editor', 'can' => ['content.edit']],
        ['label' => 'Service Forms', 'route' => 'admin.services.index', 'can' => ['services.forms']],
        ['label' => 'Settings', 'route' => 'admin.settings', 'can' => ['prices.edit', 'provider.config', 'settings.change']],
    ];

    $viewer = request()->user();

    $adminPages = array_values(array_filter(
        $adminPages,
        static fn (array $page): bool => empty($page['can'])
            || ($viewer !== null && (bool) array_intersect($page['can'], $viewer->adminPermissionSlugs()))
    ));

    // The two audit screens are the owner's, so the links appear only for accounts
    // named in the owner list. Showing every admin a door that refuses them is
    // worse than a quieter menu: it invites them to assume they have access.
    if (\App\Http\Middleware\AuditOwnerAccess::isOwner(request())) {
        $adminPages[] = ['label' => 'All Administrators', 'route' => 'admin.administrators'];
        $adminPages[] = ['label' => 'Activity Log', 'route' => 'admin.activity-log'];
    }

    // Who may do what is decided here, and only by the Master Admin.
    if ($viewer !== null && $viewer->isMasterAdmin()) {
        $adminPages[] = ['label' => 'Master Admin Permissions', 'route' => 'admin.permissions.index'];
    }

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
                            @if(!empty($page['badge']))
                                <span class="admin-quick-nav-count">{{ $page['badge'] }}</span>
                            @endif
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
