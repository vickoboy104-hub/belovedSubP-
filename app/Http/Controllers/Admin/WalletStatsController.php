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

    /**
     * The trading day the owner means is the Nigerian one. The app stores its
     * timestamps in UTC, so "today" has to be cut at Lagos midday-in-UTC or every
     * payment made before 1am here is filed under yesterday.
     */
    private const TRADING_ZONE = 'Africa/Lagos';

    /**
     * Channels where money actually entered the business. A refund or a referral
     * payout also arrives as a credit, but it is money moving around inside an
     * account the customer already funded, so counting it would inflate the day.
     */
    private const MONEY_IN_CHANNELS = [
        'flutterwave',
        'flutterwave_virtual_account',
        'admin_manual_credit',
    ];

    /** A day that needs more than this is a report, not a board. */
    private const PAYMENT_ROWS = 200;

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

        $paidOn = $this->tradingDay($request->query('paid_on'));
        $paidKind = $request->query('paid_kind') === 'service' ? 'service' : 'money';
        [$paidFrom, $paidUntil] = $this->dayWindow($paidOn);

        $payments = $paidKind === 'money'
            ? $this->whoFunded($paidFrom, $paidUntil)
            : $this->whoBought($paidFrom, $paidUntil);

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
            'paidKind' => $paidKind,
            'paidOn' => $paidOn,
            'paidOnLabel' => Carbon::parse($paidOn)->format('l, j F Y'),
            'paidToday' => $paidOn === Carbon::now(self::TRADING_ZONE)->toDateString(),
            'payments' => $payments['rows'],
            'paidPeople' => $payments['people'],
            'paidKobo' => $payments['kobo'],
            'paidRefundedKobo' => $payments['refunded'],
            'paidLimit' => self::PAYMENT_ROWS,
        ]);
    }

    /**
     * The day the owner asked for, in his own calendar. Anything he cannot have
     * meant falls back to today rather than throwing at a statistics page.
     */
    private function tradingDay(mixed $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw !== '') {
            try {
                return Carbon::parse($raw, self::TRADING_ZONE)->setTimezone(self::TRADING_ZONE)->toDateString();
            } catch (\Throwable) {
                // Fall through to today.
            }
        }

        return Carbon::now(self::TRADING_ZONE)->toDateString();
    }

    /**
     * @return array{0: string, 1: string}  The stored (UTC) bounds of that day.
     */
    private function dayWindow(string $day): array
    {
        $start = Carbon::parse($day.' 00:00:00', self::TRADING_ZONE)->setTimezone('UTC');
        $end = Carbon::parse($day.' 23:59:59', self::TRADING_ZONE)->setTimezone('UTC');

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }

    /**
     * Every customer who put real money into the business on that day.
     */
    private function whoFunded(string $from, string $until): array
    {
        $rows = DB::table('wallet_transactions as wt')
            ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('wt.type', 'credit')
            ->where('wt.status', 'success')
            ->whereIn('wt.channel', self::MONEY_IN_CHANNELS)
            ->whereBetween('wt.created_at', [$from, $until])
            ->orderByDesc('wt.created_at')
            ->limit(self::PAYMENT_ROWS)
            ->get(['u.id as user_id', 'u.name', 'u.phone', 'u.email', 'wt.amount', 'wt.channel', 'wt.created_at'])
            ->map(fn ($row) => (object) [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'phone' => (string) ($row->phone ?? ''),
                'email' => (string) ($row->email ?? ''),
                'kobo' => (int) $row->amount,
                'label' => self::SOURCE_LABELS[(string) $row->channel] ?? str_replace('_', ' ', (string) $row->channel),
                'at' => $this->clock((string) $row->created_at),
            ]);

        return [
            'rows' => $rows,
            'people' => $rows->pluck('user_id')->unique()->count(),
            'kobo' => (int) $rows->sum('kobo'),
            'refunded' => 0,
        ];
    }

    /**
     * Every customer whose wallet was charged for a service on that day. A
     * purchase is paid for the moment it is placed, so a later failure or refund
     * still belongs on the list - it is shown with its status rather than hidden.
     */
    private function whoBought(string $from, string $until): array
    {
        $rows = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('services as s', 's.id', '=', 'o.service_id')
            ->whereBetween('o.created_at', [$from, $until])
            ->orderByDesc('o.created_at')
            ->limit(self::PAYMENT_ROWS)
            ->get([
                'o.id', 'o.user_id', 'o.amount', 'o.status', 'o.customer_ref', 'o.meta', 'o.created_at',
                'u.name', 'u.phone', 'u.email', 's.name as service_name',
            ])
            ->map(function ($row) {
                $meta = json_decode((string) ($row->meta ?? ''), true) ?: [];

                return (object) [
                    'user_id' => (int) $row->user_id,
                    'name' => (string) $row->name,
                    'phone' => (string) ($row->phone ?? ''),
                    'email' => (string) ($row->email ?? ''),
                    'kobo' => (int) $row->amount,
                    'label' => (string) ($row->service_name ?: strtoupper((string) ($meta['type'] ?? 'Service'))),
                    'detail' => (string) ($meta['plan'] ?? ''),
                    'ref' => (string) ($row->customer_ref ?? ''),
                    'status' => (string) $row->status,
                    'at' => $this->clock((string) $row->created_at),
                ];
            });

        $refunded = $rows->where('status', 'refunded')->sum('kobo');

        return [
            'rows' => $rows,
            'people' => $rows->pluck('user_id')->unique()->count(),
            'kobo' => (int) $rows->sum('kobo'),
            'refunded' => (int) $refunded,
        ];
    }

    /** A stored (UTC) timestamp, said out loud in the owner's own hours. */
    private function clock(string $stored): string
    {
        return Carbon::parse($stored)->setTimezone(self::TRADING_ZONE)->format('g:i A');
    }
}
