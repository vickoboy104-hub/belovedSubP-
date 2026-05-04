<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VtuController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WalletTransactionsController;
use App\Http\Controllers\Admin\SupportChatsController;
use App\Http\Controllers\FlutterwaveController;
use App\Http\Controllers\VirtualAccountController;
use App\Http\Controllers\SupportBotController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Admin\WebsiteEditorController;
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
    Route::get('/vtu/nin/reports', [VtuController::class, 'ninSlipReports'])->name('vtu.nin.reports');
    Route::get('/vtu/nin-validation', [VtuController::class, 'ninValidationForm'])->name('vtu.nin-validation');
    Route::post('/vtu/nin-validation', [VtuController::class, 'ninValidationSubmit'])->name('vtu.nin-validation.submit');

    Route::get('/vtu/bvn', [VtuController::class, 'bvnForm'])->name('vtu.bvn');
    Route::post('/vtu/bvn/verify', [VtuController::class, 'bvnVerify'])->name('vtu.bvn.verify');
    Route::post('/vtu/bvn/retrieve', [VtuController::class, 'bvnRetrieve'])->name('vtu.bvn.retrieve');

    Route::get('/vtu/orders', [VtuController::class, 'orders'])->name('vtu.orders');
    Route::get('/vtu/receipt/{id}', [VtuController::class, 'receipt'])->name('vtu.receipt');
    Route::get('/vtu/profit-calculator', [VtuController::class, 'profitCalculator'])->name('vtu.profit-calculator');

    // ✅ Wallet pages still handled by WalletController
    Route::get('/wallet/fund', [WalletController::class, 'fundForm'])->name('wallet.fund');

    Route::post('/wallet/fund', [FlutterwaveController::class, 'initialize'])->name('wallet.fund.submit');

    Route::get('/wallet/flutterwave/callback', [FlutterwaveController::class, 'callback'])->name('flutterwave.callback');

    Route::post('/wallet/virtual-account/assign', [VirtualAccountController::class, 'assign'])
        ->name('wallet.virtual-account.assign');
    Route::post('/wallet/virtual-account/temporary', [VirtualAccountController::class, 'assignTemporary'])
        ->name('wallet.virtual-account.temporary');

    // Wallet transactions page
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

    Route::get('/ajax/gsubz/plans', [VtuController::class, 'gsubzPlans'])->name('gsubz.plans');
    Route::post('/referral/link/generate', [ReferralController::class, 'generate'])->name('referral.generate');
    Route::post('/referral/withdraw', [ReferralController::class, 'withdraw'])->name('referral.withdraw');
    Route::post('/notifications/read-all', [VtuController::class, 'markUserNotificationsRead'])->name('notifications.read-all');
    Route::get('/notifications', [VtuController::class, 'notificationsIndex'])->name('notifications.index');

    Route::get('/support/bot', [SupportBotController::class, 'index'])->name('support.bot');
    Route::post('/support/bot', [SupportBotController::class, 'store'])->name('support.bot.store');
    Route::get('/support/chat/sessions', [SupportBotController::class, 'sessions'])->name('support.chat.sessions');
    Route::get('/support/chat/{ticket}/messages', [SupportBotController::class, 'messages'])->name('support.chat.messages');
    Route::post('/support/chat/{ticket}/messages', [SupportBotController::class, 'sendMessage'])->name('support.chat.messages.send');
});

Route::middleware(['auth', 'verified', 'is_admin', 'no_cache'])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/orders', [OrdersController::class, 'index'])->name('admin.orders');
    Route::get('/users', [UsersController::class, 'index'])->name('admin.users');
    Route::get('/users/{user}', [UsersController::class, 'show'])->name('admin.users.show');
    Route::post('/users/{user}/profile', [UsersController::class, 'updateProfile'])->name('admin.users.profile');
    Route::post('/users/{user}/discount', [UsersController::class, 'updateDiscount'])->name('admin.users.discount');
    Route::post('/users/{user}/admin', [UsersController::class, 'updateAdmin'])->name('admin.users.admin');
    Route::post('/users/{user}/reset-password', [UsersController::class, 'resetPassword'])->name('admin.users.reset-password');
    Route::post('/users/{user}/fund-wallet', [UsersController::class, 'fundWallet'])->name('admin.users.fund-wallet');
    Route::post('/users/{user}/adjust-wallet', [UsersController::class, 'adjustWallet'])->name('admin.users.adjust-wallet');
    Route::get('/wallet-transactions', [WalletTransactionsController::class, 'index'])->name('admin.wallet.transactions');
    Route::post('/notifications/read-all', [DashboardController::class, 'markNotificationsRead'])->name('admin.notifications.read-all');
    Route::get('/notifications', [DashboardController::class, 'notificationsIndex'])->name('admin.notifications.index');
    Route::get('/support/chats', [SupportChatsController::class, 'index'])->name('admin.support.chats');
    Route::post('/metrics/reset', [DashboardController::class, 'resetMetric'])->name('admin.metrics.reset');
    Route::get('/website-editor', [WebsiteEditorController::class, 'index'])->name('admin.website-editor');
    Route::post('/website-editor', [WebsiteEditorController::class, 'update'])->name('admin.website-editor.update');
    Route::post('/website-editor/reset', [WebsiteEditorController::class, 'reset'])->name('admin.website-editor.reset');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('admin.settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
});
