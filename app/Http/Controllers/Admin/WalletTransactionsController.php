<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\AdminActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The transaction explorer.
 *
 * Three rules shape it. Every filter is answered by the database, so a page never
 * carries more rows than it shows and a phone is not asked to hold the ledger. The
 * filters that identify a person (user id, reference, order id) are exact, because
 * a partial match on a wallet row is how the wrong customer gets blamed. And reading
 * here moves nothing: there is no POST on this screen, no button that credits,
 * refunds, freezes or blocks, and a failed row is shown as a failed row - not as a
 * suspicion about the customer.
 */
class WalletTransactionsController extends Controller
{
    private const STATUSES = ['pending', 'success', 'failed'];

    private const TYPES = ['credit', 'debit'];

    private const SORTS = ['newest', 'oldest', 'highest', 'lowest'];

    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => mb_substr(trim((string) ($request->query('q') ?: $request->query('search', ''))), 0, 120),
            'user_id' => $request->integer('user_id') > 0 ? $request->integer('user_id') : null,
            'txn_id' => $request->integer('txn_id') > 0 ? $request->integer('txn_id') : null,
            'reference' => mb_substr(trim((string) $request->query('reference', '')), 0, 191),
            'order_id' => $request->integer('order_id') > 0 ? $request->integer('order_id') : null,
            'status' => in_array((string) $request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '',
            'type' => in_array((string) $request->query('type'), self::TYPES, true) ? (string) $request->query('type') : '',
            'channel' => mb_substr(trim((string) $request->query('channel', '')), 0, 64),
            'admin' => $request->integer('admin') > 0 ? $request->integer('admin') : null,
            'from' => $this->date((string) $request->query('from', '')),
            'to' => $this->date((string) $request->query('to', '')),
            'sort' => in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'newest',
        ];

        $query = WalletTransaction::query()->with(['wallet.user']);

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function ($root) use ($like): void {
                $root->where('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('channel', 'like', $like)
                    ->orWhereHas('wallet.user', function ($u) use ($like): void {
                        $u->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        if ($filters['user_id'] !== null) {
            $query->whereHas('wallet.user', fn ($u) => $u->where('users.id', $filters['user_id']));
        }

        if ($filters['txn_id'] !== null) {
            $query->where('id', $filters['txn_id']);
        }

        if ($filters['reference'] !== '') {
            $query->where('reference', $filters['reference']);
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if ($filters['channel'] !== '') {
            $query->where('channel', $filters['channel']);
        }

        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // "Which rows did this administrator touch" is answered from the trail, not
        // from a column on the ledger: a hand credit already carries its own
        // reference, and the trail row names the person who wrote it.
        if ($filters['admin'] !== null) {
            $query->whereIn('reference', AdminActivityLog::query()
                ->where('admin_user_id', $filters['admin'])
                ->whereNotNull('reference')
                ->select('reference'));
        }

        // An order is not a wallet row; the link between them is the reference this
        // site sent when it debited. If the order never reached a debit there is
        // nothing to show, and the page says that instead of listing unrelated rows.
        $orderNote = null;

        if ($filters['order_id'] !== null) {
            $order = Order::query()->find($filters['order_id']);
            $linked = array_values(array_filter([(string) ($order?->provider_reference ?? '')]));

            $query->whereIn('reference', $linked);

            if ($order === null) {
                $orderNote = 'Order #'.$filters['order_id'].' does not exist.';
            } elseif ($linked === []) {
                $orderNote = 'Order #'.$filters['order_id'].' has no wallet reference recorded, so no ledger row is claimed for it.';
            }
        }

        $query = match ($filters['sort']) {
            'oldest' => $query->orderBy('id'),
            'highest' => $query->orderByDesc('amount')->orderByDesc('id'),
            'lowest' => $query->orderBy('amount')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $perPage = max(10, min(100, (int) $request->query('per_page', 20)));
        $transactions = $query->paginate($perPage)->appends($request->query());

        // Reading the ledger is itself an event worth recording: the question
        // "who looked at this customer's money" has no other answer. What was
        // typed stays out of the trail - a search box can hold anything, including
        // someone's NIN - so the record names the filters and how many rows came
        // back, not the query.
        $active = array_filter(
            array_diff_key($filters, ['sort' => true]),
            static fn ($value): bool => $value !== null && $value !== ''
        );

        if ($active !== []) {
            $this->audit->recordQuietly('success', 'transaction_review', 'wallet.transactions_searched', 'Searched the wallet transaction ledger.', [
                'changes' => [
                    'filters' => array_keys($active),
                    'results' => $transactions->total(),
                ],
                'throttle_seconds' => 60,
            ]);
        }

        return view('admin.wallet-transactions', [
            'transactions' => $transactions,
            'filters' => $filters,
            'activeFilters' => $active,
            'perPage' => $perPage,
            'orderNote' => $orderNote,
            'channels' => WalletTransaction::query()->whereNotNull('channel')->distinct()->orderBy('channel')->limit(60)->pluck('channel'),
            'administrators' => \App\Models\User::query()->where('is_admin', true)->orderBy('id')->get(['id', 'name']),
            'activeCount' => count($active),
        ]);
    }

    private function date(string $value): string
    {
        $stamp = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $stamp !== false && $stamp->format('Y-m-d') === $value ? $value : '';
    }
}
