<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WalletStatsController extends Controller
{
    /**
     * How a credit arrived, in words an owner can read. The channel column is a
     * free string, so an unrecognised value still gets drawn - it just takes a
     * plain label rather than vanishing off the chart.
     */
    private const SOURCE_LABELS = [
        'flutterwave' => 'Card and bank checkout',
        'flutterwave_virtual_account' => 'Virtual account transfer',
        'admin_manual_credit' => 'Top-up by an admin',
        'admin_wallet_adjustment' => 'Correction by an admin',
        'referral_withdrawal' => 'Referral earnings cashed out',
        'refund' => 'Refund for a failed service',
    ];

    private const WINDOW_DAYS = 30;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));

        $users = User::query()
            ->select(['users.*', DB::raw('coalesce(wallets.balance, 0) as wallet_balance_kobo')])
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($inner) use ($like) {
                    $inner->where('users.name', 'like', $like)
                        ->orWhere('users.email', 'like', $like)
                        ->orWhere('users.phone', 'like', $like);
                });
            })
            ->orderByDesc('wallet_balance_kobo')
            ->orderByDesc('users.id')
            ->paginate(15)
            ->withQueryString();

        // A bar only means something against the richest wallet in the whole
        // system, not against the richest name on this page.
        $topBalanceKobo = max(1, (int) DB::table('wallets')->max('balance'));

        $sourceRows = DB::table('wallet_transactions')
            ->select('channel', DB::raw('sum(amount) as total_kobo'), DB::raw('count(id) as deposits'))
            ->where('type', 'credit')
            ->where('status', 'success')
            ->groupBy('channel')
            ->orderByDesc('total_kobo')
            ->get()
            ->map(function ($row) {
                $channel = (string) ($row->channel ?? '');

                return (object) [
                    'channel' => $channel,
                    'label' => self::SOURCE_LABELS[$channel] ?? ($channel !== '' ? str_replace('_', ' ', $channel) : 'Unspecified source'),
                    'kobo' => (int) $row->total_kobo,
                    'deposits' => (int) $row->deposits,
                ];
            })
            ->values();

        $fundedKobo = (int) $sourceRows->sum('kobo');
        $largestSourceKobo = max(1, (int) $sourceRows->max('kobo'));

        $since = Carbon::now()->startOfDay()->subDays(self::WINDOW_DAYS - 1);

        $series = collect(range(0, self::WINDOW_DAYS - 1))
            ->mapWithKeys(fn (int $offset) => [$since->copy()->addDays($offset)->toDateString() => 0]);

        $recentCredits = DB::table('wallet_transactions')
            ->where('type', 'credit')
            ->where('status', 'success')
            ->where('created_at', '>=', $since)
            ->get(['amount', 'created_at']);

        $creditsInWindow = $recentCredits->count();
        $koboInWindow = 0;
        foreach ($recentCredits as $credit) {
            $day = Carbon::parse($credit->created_at)->toDateString();
            if ($series->has($day)) {
                $series[$day] += (int) $credit->amount;
            }
            $koboInWindow += (int) $credit->amount;
        }

        // Names are already sorted by balance, so this page only asks for the
        // money trails of the fifteen people it can actually show.
        $ids = $users->getCollection()->pluck('id')->all();
        $trails = collect();

        if ($ids !== []) {
            $trails = DB::table('wallet_transactions as wt')
                ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
                ->whereIn('w.user_id', $ids)
                ->where('wt.type', 'credit')
                ->where('wt.status', 'success')
                ->select('w.user_id', 'wt.channel', DB::raw('sum(wt.amount) as total_kobo'), DB::raw('count(wt.id) as deposits'))
                ->groupBy('w.user_id', 'wt.channel')
                ->orderByDesc('total_kobo')
                ->get()
                ->groupBy('user_id')
                ->map(function ($rows) {
                    return $rows->map(function ($row) {
                        $channel = (string) ($row->channel ?? '');

                        return (object) [
                            'label' => self::SOURCE_LABELS[$channel] ?? ($channel !== '' ? str_replace('_', ' ', $channel) : 'Unspecified source'),
                            'kobo' => (int) $row->total_kobo,
                            'deposits' => (int) $row->deposits,
                        ];
                    })->values();
                });
        }

        return view('admin.wallet-stats', [
            'users' => $users,
            'search' => $search,
            'topBalanceKobo' => $topBalanceKobo,
            'totalHeldKobo' => (int) DB::table('wallets')->sum('balance'),
            'totalUsers' => User::count(),
            'fundedWallets' => DB::table('wallets')->where('balance', '>', 0)->count(),
            'fundedKobo' => $fundedKobo,
            'largestSourceKobo' => $largestSourceKobo,
            'sources' => $sourceRows,
            'series' => $series,
            'peakSeriesKobo' => max(1, (int) $series->max()),
            'creditsInWindow' => $creditsInWindow,
            'koboInWindow' => $koboInWindow,
            'trails' => $trails,
            'windowDays' => self::WINDOW_DAYS,
        ]);
    }
}
