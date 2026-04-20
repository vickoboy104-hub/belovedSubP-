<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $totalUsers = User::count();

        $totalOrders = Order::count();
        $successfulOrders = Order::where('status', 'success')->count();
        $pendingOrders = Order::where('status', 'pending')->count();

        $totalSales = (int) Order::where('status', 'success')->sum('amount');
        $totalProfit = (int) Order::where('status', 'success')->sum('profit');

        $totalFunding = (int) WalletTransaction::whereIn('channel', ['flutterwave', 'flutterwave_virtual_account'])
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('amount');

        $totalPurchases = (int) WalletTransaction::where('type', 'debit')
            ->where('status', 'success')
            ->sum('amount');
        $totalFunding = max(0, $totalFunding - (int) setting('metrics_total_funding_offset_kobo', 0));
        $totalPurchases = max(0, $totalPurchases - (int) setting('metrics_total_purchases_offset_kobo', 0));
        $totalProfit = max(0, $totalProfit - (int) setting('metrics_total_profit_offset_kobo', 0));

        $todayProfit = (int) Order::where('status', 'success')->whereDate('created_at', now()->toDateString())->sum('profit');
        $monthProfit = (int) Order::where('status', 'success')->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('profit');
        $yearProfit = (int) Order::where('status', 'success')->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()])->sum('profit');

        $profitPeriod = strtolower(trim((string) $request->input('profit_period', 'today')));
        $profitDate = trim((string) $request->input('profit_date', ''));
        $profitFrom = trim((string) $request->input('profit_from', ''));
        $profitTo = trim((string) $request->input('profit_to', ''));
        $profitQuery = Order::query()->where('status', 'success');

        if ($profitPeriod === 'month') {
            $profitQuery->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($profitPeriod === 'date' && $profitDate !== '') {
            $profitQuery->whereDate('created_at', $profitDate);
        } elseif ($profitPeriod === 'range' && $profitFrom !== '' && $profitTo !== '') {
            $profitQuery->whereBetween('created_at', [
                \Carbon\Carbon::parse($profitFrom)->startOfDay(),
                \Carbon\Carbon::parse($profitTo)->endOfDay(),
            ]);
        } else {
            $profitPeriod = 'today';
            $profitQuery->whereDate('created_at', now()->toDateString());
        }

        $profitFilter = [
            'period' => $profitPeriod,
            'date' => $profitDate,
            'from' => $profitFrom,
            'to' => $profitTo,
        ];
        $profitFilterResult = [
            'orders' => (clone $profitQuery)->count(),
            'profit' => (int) (clone $profitQuery)->sum('profit'),
        ];

        $recentOrders = Order::latest()->take(10)->get();
        $recentTransactions = WalletTransaction::latest()->take(10)->get();
        $adminNotifications = collect();
        $unreadAdminNotifications = 0;

        $user = auth()->user();
        if ($user && Schema::hasTable('notifications')) {
            try {
                $adminNotifications = $user->notifications()->latest()->take(20)->get();
                $unreadAdminNotifications = $user->unreadNotifications()->count();
            } catch (\Throwable $e) {
                Log::warning('Failed to load admin notifications.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($user) {
            Log::warning('Skipped admin notifications load: notifications table is missing.');
        }

        return view('admin.dashboard', compact(
            'totalUsers',
            'totalOrders',
            'successfulOrders',
            'pendingOrders',
            'totalSales',
            'totalProfit',
            'totalFunding',
            'totalPurchases',
            'todayProfit',
            'monthProfit',
            'yearProfit',
            'profitFilter',
            'profitFilterResult',
            'recentOrders',
            'recentTransactions',
            'adminNotifications',
            'unreadAdminNotifications'
        ));
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user && Schema::hasTable('notifications')) {
            try {
                $user->unreadNotifications->markAsRead();
            } catch (\Throwable $e) {
                Log::warning('Failed to mark admin notifications as read.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('success', 'Admin notifications marked as read.');
    }

    public function notificationsIndex(Request $request)
    {
        $user = $request->user();
        $notifications = collect();
        $unreadCount = 0;

        if ($user && Schema::hasTable('notifications')) {
            try {
                $unreadCount = $user->unreadNotifications()->count();
                $notifications = $user->notifications()->latest()->paginate(60);
            } catch (\Throwable $e) {
                Log::warning('Failed loading admin notifications page.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('admin.notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    public function resetMetric(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'metric' => ['required', 'string', 'in:funding,purchases,profit,all'],
        ]);

        $metric = (string) $payload['metric'];

        $currentFunding = (int) WalletTransaction::whereIn('channel', ['flutterwave', 'flutterwave_virtual_account'])
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('amount');
        $currentPurchases = (int) WalletTransaction::where('type', 'debit')
            ->where('status', 'success')
            ->sum('amount');
        $currentProfit = (int) Order::where('status', 'success')->sum('profit');

        if (in_array($metric, ['funding', 'all'], true)) {
            Setting::updateOrCreate(
                ['key' => 'metrics_total_funding_offset_kobo'],
                ['value' => (string) $currentFunding]
            );
        }

        if (in_array($metric, ['purchases', 'all'], true)) {
            Setting::updateOrCreate(
                ['key' => 'metrics_total_purchases_offset_kobo'],
                ['value' => (string) $currentPurchases]
            );
        }

        if (in_array($metric, ['profit', 'all'], true)) {
            Setting::updateOrCreate(
                ['key' => 'metrics_total_profit_offset_kobo'],
                ['value' => (string) $currentProfit]
            );
        }

        settings_flush_cache();

        return back()->with('success', 'Selected metric totals have been reset.');
    }
}
