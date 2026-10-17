<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VtuController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ManualOrdersController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ServiceFormsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WalletTransactionsController;
use App\Http\Controllers\Admin\FundingRequestController;
use App\Http\Controllers\Admin\HeldDepositController;
use App\Http\Controllers\Admin\WalletStatsController;
use App\Http\Controllers\Admin\SupportChatsController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\FlutterwaveController;
use App\Http\Controllers\VirtualAccountController;
use App\Http\Controllers\SupportBotController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Admin\WebsiteEditorController;
use App\Http\Controllers\Admin\AdministratorsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\PermissionsController;
use App\Http\Controllers\Admin\SettlementLinkController;
use App\Http\Controllers\ReferralController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

Route::get('/', function () {
    return view('home');
})->name('home');
Route::view('/download-app', 'download-app')->name('download.app');

Route::view('/guides/buy-cheap-data-nigeria-2026', 'guides.cheap-data')->name('guides.cheap-data');
Route::view('/guides/fund-vtu-wallet', 'guides.fund-wallet')->name('guides.fund-wallet');
Route::view('/guides/buy-electricity-bills-online-nigeria', 'guides.electricity-bills')->name('guides.electricity-bills');
Route::view('/guides/nin-services-nigeria', 'guides.nin-services')->name('guides.nin-services');
Route::view('/guides/education-result-checker-pins-nigeria', 'guides.education-services')->name('guides.education-services');
Route::view('/guides/premium-apps-subscription-nigeria', 'guides.premium-apps')->name('guides.premium-apps');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/r/{code}', [ReferralController::class, 'visit'])->name('referral.visit');

Route::post('/wallet/flutterwave/webhook-v2', [FlutterwaveController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('flutterwave.webhook');

require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified', 'no_cache'])->group(function () {

    Route::get('/dashboard', [VtuController::class, 'dashboard'])->name('dashboard');
    Route::view('/identity', 'identity.index')->name('identity.index');

    Route::get('/vtu/airtime', [VtuController::class, 'airtimeForm'])->name('vtu.airtime');
    Route::get('/vtu/airtime/{service}', [VtuController::class, 'airtimeServiceForm'])->name('vtu.airtime.service');
    Route::post('/vtu/airtime/buy', [VtuController::class, 'buyAirtime'])->name('vtu.airtime.buy');

    Route::get('/vtu/data', [VtuController::class, 'dataForm'])->name('vtu.data');
    Route::get('/vtu/data/{service}', [VtuController::class, 'dataServiceForm'])->name('vtu.data.service');
    Route::post('/vtu/data/buy', [VtuController::class, 'buyData'])->name('vtu.data.buy');

    Route::get('/vtu/recharge-card', [VtuController::class, 'rechargeCardForm'])->name('vtu.recharge-card');
    Route::post('/vtu/recharge-card/buy', [VtuController::class, 'buyRechargeCard'])->name('vtu.recharge-card.buy');

    Route::get('/vtu/cable', [VtuController::class, 'cableForm'])->name('vtu.cable');
    Route::get('/vtu/cable/{service}', [VtuController::class, 'cableServiceForm'])->name('vtu.cable.service');
    Route::post('/vtu/cable/buy', [VtuController::class, 'buyCable'])->name('vtu.cable.buy');

    Route::get('/vtu/electricity', [VtuController::class, 'electricityForm'])->name('vtu.electricity');
    Route::get('/vtu/electricity/{service}', [VtuController::class, 'electricityServiceForm'])->name('vtu.electricity.service');
    Route::post('/vtu/electricity/buy', [VtuController::class, 'buyElectricity'])->name('vtu.electricity.buy');

    Route::get('/vtu/exam', [VtuController::class, 'examPinForm'])->name('vtu.exam');
    Route::get('/vtu/exam/{service}', [VtuController::class, 'examServiceForm'])->name('vtu.exam.service');
    Route::post('/vtu/exam/buy', [VtuController::class, 'buyExamPin'])->name('vtu.exam.buy');

    Route::get('/vtu/premium-apps', [VtuController::class, 'premiumAppsForm'])->name('vtu.premium-apps');
    Route::post('/vtu/premium-apps/buy', [VtuController::class, 'buyPremiumApp'])->name('vtu.premium-apps.buy');

    Route::get('/vtu/nin', [VtuController::class, 'ninForm'])->name('vtu.nin');
    Route::post('/vtu/nin/search', [VtuController::class, 'ninSearch'])->name('vtu.nin.search');
    Route::post('/vtu/nin/print', [VtuController::class, 'ninPrint'])->name('vtu.nin.print');
    Route::get('/vtu/nin/slip/{order}', [VtuController::class, 'ninSlip'])->name('vtu.nin.slip');
    Route::get('/vtu/nin/reports', [VtuController::class, 'ninSlipReports'])->name('vtu.nin.reports');
    Route::get('/vtu/nin-validation', [VtuController::class, 'ninValidationForm'])->name('vtu.nin-validation');
    Route::post('/vtu/nin-validation', [VtuController::class, 'ninValidationSubmit'])->name('vtu.nin-validation.submit');

    Route::get('/vtu/bvn', [VtuController::class, 'bvnForm'])->name('vtu.bvn');
    Route::post('/vtu/bvn/verify', [VtuController::class, 'bvnVerify'])->name('vtu.bvn.verify');
    Route::post('/vtu/bvn/retrieve', [VtuController::class, 'bvnRetrieve'])->name('vtu.bvn.retrieve');

    // A verification result is shown once, from a grant this session holds, and the
    // slip is a document of its own so the browser can print that page alone.
    Route::get('/vtu/bvn/details/{order}', [VtuController::class, 'bvnDetails'])->name('vtu.bvn.details');
    Route::post('/vtu/bvn/details/{order}/seen', [VtuController::class, 'bvnDetailsAck'])->name('vtu.bvn.details.ack');
    Route::post('/vtu/bvn/print', [VtuController::class, 'bvnPrint'])->name('vtu.bvn.print');
    Route::get('/vtu/bvn/slip/{order}', [VtuController::class, 'bvnSlip'])->name('vtu.bvn.slip');
    Route::get('/vtu/bvn/slip/{order}/sheet', [VtuController::class, 'bvnSlipSheet'])->name('vtu.bvn.slip.sheet');

    Route::get('/vtu/orders', [VtuController::class, 'orders'])->name('vtu.orders');
    Route::get('/vtu/manual/{service}', [VtuController::class, 'manualServiceForm'])->name('vtu.manual.form');
    Route::post('/vtu/manual/{service}', [VtuController::class, 'manualServiceSubmit'])->name('vtu.manual.submit');
    Route::get('/vtu/receipt/{id}', [VtuController::class, 'receipt'])->name('vtu.receipt');
    Route::get('/vtu/receipt/{id}/result-file', [VtuController::class, 'receiptFile'])->name('vtu.receipt.file');
    Route::get('/vtu/profit-calculator', [VtuController::class, 'profitCalculator'])->name('vtu.profit-calculator');

    // ✅ Wallet pages still handled by WalletController
    Route::get('/wallet/fund', [WalletController::class, 'fundForm'])->name('wallet.fund');

    Route::post('/wallet/fund', [FlutterwaveController::class, 'initialize'])->name('wallet.fund.submit');

    Route::get('/wallet/flutterwave/callback', [FlutterwaveController::class, 'callback'])->name('flutterwave.callback');

    Route::post('/wallet/virtual-account/assign', [VirtualAccountController::class, 'assign'])
        ->name('wallet.virtual-account.assign');
    Route::post('/wallet/virtual-account/temporary', [VirtualAccountController::class, 'assignTemporary'])
        ->name('wallet.virtual-account.temporary');

    // A webhook only ever arrives if Flutterwave can reach this site from the
    // outside, so the customer can always ask directly what has been paid in.
    Route::post('/wallet/deposits/check', [FlutterwaveController::class, 'checkDeposits'])
        ->name('wallet.deposits.check');

    // Wallet transactions page
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

    Route::get('/ajax/gsubz/plans', [VtuController::class, 'gsubzPlans'])->name('gsubz.plans');
    Route::get('/referral', [ReferralController::class, 'index'])->name('referral.index');
    Route::post('/referral/link/generate', [ReferralController::class, 'generate'])->name('referral.generate');
    Route::post('/referral/withdraw', [ReferralController::class, 'withdraw'])->name('referral.withdraw');
    Route::get('/notifications/feed', [VtuController::class, 'notificationFeed'])->name('notifications.feed');
    Route::post('/notifications/read-all', [VtuController::class, 'markUserNotificationsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notificationId}/read', [VtuController::class, 'markUserNotificationRead'])->name('notifications.read');
    Route::get('/notifications', [VtuController::class, 'notificationsIndex'])->name('notifications.index');

    Route::get('/support/bot', [SupportBotController::class, 'index'])->name('support.bot');
    Route::post('/support/bot', [SupportBotController::class, 'store'])->name('support.bot.store');
    Route::get('/support/chat/sessions', [SupportBotController::class, 'sessions'])->name('support.chat.sessions');
    Route::get('/support/chat/{ticket}/messages', [SupportBotController::class, 'messages'])->name('support.chat.messages');
    Route::post('/support/chat/{ticket}/messages', [SupportBotController::class, 'sendMessage'])->name('support.chat.messages.send');
});

Route::middleware(['auth', 'verified', 'is_admin', 'no_cache'])->prefix('admin')->group(function () {
    // Reading the desk summary needs an administrator and nothing more: it shows
    // counts, not a customer's records. Every screen past this point names the
    // permission that opens it, and the name is checked on the server, so a saved
    // form post from a person without the grant is refused the same way a typed URL
    // is.
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::post('/notifications/read-all', [DashboardController::class, 'markNotificationsRead'])->name('admin.notifications.read-all');
    Route::get('/notifications', [DashboardController::class, 'notificationsIndex'])->name('admin.notifications.index');
    Route::post('/metrics/reset', [DashboardController::class, 'resetMetric'])->middleware('can_admin:settings.change')->name('admin.metrics.reset');

    Route::get('/orders', [OrdersController::class, 'index'])->middleware('can_admin:orders.view')->name('admin.orders');
    Route::get('/orders/{order}/keys', [OrdersController::class, 'keys'])->middleware('can_admin:orders.keys')->name('admin.orders.keys');
    Route::post('/orders/{order}/keys', [OrdersController::class, 'storeKeys'])->middleware('can_admin:orders.keys')->name('admin.orders.keys.store');

    Route::get('/manual-orders', [ManualOrdersController::class, 'index'])->middleware('can_admin:orders.manual.view')->name('admin.manual-orders.index');
    Route::get('/manual-orders/{order}', [ManualOrdersController::class, 'show'])->middleware('can_admin:orders.manual.view')->name('admin.manual-orders.show');
    Route::get('/manual-orders/{order}/document/{field}', [ManualOrdersController::class, 'document'])->middleware('can_admin:orders.manual.view')->name('admin.manual-orders.document');
    Route::post('/manual-orders/{order}/claim', [ManualOrdersController::class, 'claim'])->middleware('can_admin:orders.manual.claim')->name('admin.manual-orders.claim');
    Route::post('/manual-orders/{order}/fulfil', [ManualOrdersController::class, 'fulfil'])->middleware('can_admin:orders.manual.answer')->name('admin.manual-orders.fulfil');
    Route::post('/manual-orders/{order}/reject', [ManualOrdersController::class, 'reject'])->middleware('can_admin:orders.manual.answer')->name('admin.manual-orders.reject');

    Route::get('/users', [UsersController::class, 'index'])->middleware('can_admin:customers.view')->name('admin.users');
    Route::get('/users/{user}', [UsersController::class, 'show'])->middleware('can_admin:customers.view')->name('admin.users.show');
    Route::post('/users/{user}/profile', [UsersController::class, 'updateProfile'])->middleware('can_admin:customers.edit')->name('admin.users.profile');
    Route::post('/users/{user}/discount', [UsersController::class, 'updateDiscount'])->middleware('can_admin:customers.discount')->name('admin.users.discount');
    Route::post('/users/{user}/admin', [UsersController::class, 'updateAdmin'])->middleware('can_admin:admins.grant')->name('admin.users.admin');
    Route::post('/users/{user}/reset-password', [UsersController::class, 'resetPassword'])->middleware('can_admin:customers.password_reset')->name('admin.users.reset-password');
    Route::post('/users/{user}/fund-wallet', [UsersController::class, 'fundWallet'])->middleware('can_admin:wallet.credit')->name('admin.users.fund-wallet');
    // One route, three different powers: taking money back, adding it and replacing
    // the figure outright are decided separately inside the action, because a person
    // allowed to correct an over-credit must not become allowed to hand one out.
    Route::post('/users/{user}/adjust-wallet', [UsersController::class, 'adjustWallet'])->middleware('can_admin:wallet.debit,wallet.credit,wallet.balance.set')->name('admin.users.adjust-wallet');

    Route::get('/wallet-transactions', [WalletTransactionsController::class, 'index'])->middleware('can_admin:wallet.transactions.view')->name('admin.wallet.transactions');
    Route::get('/wallet-deposits/held', [HeldDepositController::class, 'index'])->middleware('can_admin:wallet.held.view')->name('admin.wallet.deposits.held');
    Route::post('/wallet-deposits/held/{deposit}/credit', [HeldDepositController::class, 'credit'])->middleware('can_admin:wallet.held.resolve')->name('admin.wallet.deposits.held.credit');
    Route::post('/wallet-deposits/held/{deposit}/dismiss', [HeldDepositController::class, 'dismiss'])->middleware('can_admin:wallet.held.resolve')->name('admin.wallet.deposits.held.dismiss');
    Route::post('/wallet-requests/{transaction}/mark-settled', [FundingRequestController::class, 'markSettled'])->middleware('can_admin:wallet.settlement.declare')->name('admin.wallet.requests.mark-settled');
    Route::post('/wallet-requests/{transaction}/unmark-settled', [FundingRequestController::class, 'unmarkSettled'])->middleware('can_admin:wallet.settlement.declare')->name('admin.wallet.requests.unmark-settled');
    Route::get('/settlement-links', [SettlementLinkController::class, 'index'])->middleware('can_admin:wallet.settlement.declare')->name('admin.settlement-links.index');
    Route::post('/settlement-links', [SettlementLinkController::class, 'store'])->middleware('can_admin:wallet.settlement.declare')->name('admin.settlement-links.store');
    Route::post('/settlement-links/{link}/retract', [SettlementLinkController::class, 'retract'])->middleware('can_admin:wallet.settlement.declare')->name('admin.settlement-links.retract');
    Route::get('/wallet-stats', [WalletStatsController::class, 'index'])->middleware('can_admin:wallet.stats.view')->name('admin.wallet-stats');

    Route::get('/support/chats', [SupportChatsController::class, 'index'])->middleware('can_admin:support.manage')->name('admin.support.chats');

    Route::get('/website-editor', [WebsiteEditorController::class, 'index'])->middleware('can_admin:content.edit')->name('admin.website-editor');
    Route::post('/website-editor', [WebsiteEditorController::class, 'update'])->middleware('can_admin:content.edit')->name('admin.website-editor.update');
    Route::post('/website-editor/reset', [WebsiteEditorController::class, 'reset'])->middleware('can_admin:content.edit')->name('admin.website-editor.reset');

    // The settings page is one screen holding three kinds of power, so the route
    // admits anybody with any one of them and the save step refuses the groups that
    // person was not given. Prices, provider routing and site behaviour are granted
    // apart on purpose: the cheapest mistake an admin can make is a price, and the
    // most expensive is a provider key.
    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('can_admin:prices.edit,provider.config,settings.change')->name('admin.settings');
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('can_admin:prices.edit,provider.config,settings.change')->name('admin.settings.update');
    Route::post('/settings/provider-prices/sync', [SettingsController::class, 'syncProviderPrices'])->middleware('can_admin:prices.edit')->name('admin.settings.provider-prices.sync');

    // What each identity service asks its customer, edited here instead of in
    // code. The rates those answers select stay on the Settings page.
    Route::get('/services', [ServiceFormsController::class, 'index'])->middleware('can_admin:services.forms')->name('admin.services.index');
    Route::get('/services/{service}', [ServiceFormsController::class, 'edit'])->middleware('can_admin:services.forms')->name('admin.services.edit');
    Route::post('/services/{service}', [ServiceFormsController::class, 'update'])->middleware('can_admin:services.forms')->name('admin.services.update');
    Route::post('/services/{service}/reset', [ServiceFormsController::class, 'reset'])->middleware('can_admin:services.forms')->name('admin.services.reset');

    Route::get('/broadcast', [BroadcastController::class, 'index'])->middleware('can_admin:broadcasts.send')->name('admin.broadcast');
    Route::post('/broadcast/send', [BroadcastController::class, 'send'])->middleware('can_admin:broadcasts.send')->name('admin.broadcast.send');
    Route::get('/broadcast/export', [BroadcastController::class, 'export'])->middleware('can_admin:broadcasts.send')->name('admin.broadcast.export');

    // The trail belongs to the owner, not to every account with is_admin turned on,
    // so these two screens sit behind audit_owner as well as the admin group. The
    // owner list ships empty, which means they 403 for everyone until the owner
    // names their own account.
    Route::middleware(['audit_owner'])->group(function () {
        Route::get('/administrators', [AdministratorsController::class, 'index'])->name('admin.administrators');
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('admin.activity-log');
    });

    // Who is allowed to do what is decided here, and only by a Master Admin. The
    // slug is not in the assignable list, so no grant anywhere in the database can
    // hand this screen to an ordinary administrator: the answer comes from the
    // server's configuration, which is the owner's, not from a screen.
    Route::get('/permissions', [PermissionsController::class, 'index'])->middleware('can_admin:'.\App\Support\AdminPermissions::MASTER_PERMISSION)->name('admin.permissions.index');
    Route::post('/permissions/{user}', [PermissionsController::class, 'update'])->middleware('can_admin:'.\App\Support\AdminPermissions::MASTER_PERMISSION)->name('admin.permissions.update');
});
