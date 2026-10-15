<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wallet;
use App\Notifications\ManualOrderNotification;
use App\Services\ManualFulfilmentService;
use App\Services\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Identity services with no provider API. Customers pay at submission, so this
 * queue is the only thing between a paid request and its result.
 */
class ManualOrdersController extends Controller
{
    public function __construct(
        private readonly ManualFulfilmentService $manualServices,
        private readonly WalletLedger $ledger,
    ) {
    }

    public function index(Request $request)
    {
        $status = $this->normaliseStatus((string) $request->input('status', ''));

        // One predicate, shared by the list and by the per-service counts under it,
        // so a tab can never count requests it does not actually show.
        $byStatus = function ($query) use ($status) {
            if ($status === 'pending') {
                return $query->where('status', 'pending')->orderBy('created_at')->orderBy('id');
            }

            if ($status === 'in_progress') {
                // The jobs somebody has already picked up, so a second admin does
                // not start the same customer's record twice.
                return $query->where('status', 'pending')
                    ->where('meta->work_state', 'in_progress')
                    ->orderBy('created_at')
                    ->orderBy('id');
            }

            if ($status === 'all') {
                return $query->latest();
            }

            return $query->where('status', $status)->latest();
        };

        $query = $byStatus($this->manualServices->queueQuery()->with('user'));

        $slug = trim((string) $request->input('service', ''));
        if ($slug !== '' && $this->manualServices->find($slug)) {
            $query->where('meta->manual_service', $slug);
        } else {
            $slug = '';
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }

                $q->orWhere('provider_reference', 'like', "%{$search}%")
                    ->orWhere('customer_ref', 'like', "%{$search}%");

                $q->orWhereHas('user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            });
        }

        $counts = [
            'pending' => $this->manualServices->waitingQuery()->count(),
            'in_progress' => $this->manualServices->queueQuery()
                ->where('status', 'pending')
                ->where('meta->work_state', 'in_progress')
                ->count(),
            'success' => $this->manualServices->queueQuery()->where('status', 'success')->count(),
            'failed' => $this->manualServices->queueQuery()->where('status', 'failed')->count(),
            'all' => $this->manualServices->queueQuery()->count(),
        ];

        // So a service the admin is clearing out can be seen from the filter itself.
        $serviceCounts = $byStatus($this->manualServices->queueQuery())
            ->selectRaw("count(*) as aggregate_count, json_extract(meta, '$.manual_service') as service_slug")
            ->groupBy('service_slug')
            ->pluck('aggregate_count', 'service_slug')
            ->all();

        return view('admin.manual-orders.index', [
            'orders' => $query->paginate(20)->appends($request->query()),
            'catalogue' => $this->manualServices->catalogue(),
            'fulfilment' => $this->manualServices,
            'counts' => $counts,
            'serviceCounts' => $serviceCounts,
            'activeStatus' => $status,
            'activeService' => $slug,
        ]);
    }

    public function show(int $order)
    {
        $manualOrder = $this->findManualOrder($order);
        $manualOrder->load('user');

        $meta = is_array($manualOrder->meta) ? $manualOrder->meta : [];
        $slug = (string) ($meta['manual_service'] ?? '');
        $definition = $this->manualServices->find($slug);

        return view('admin.manual-orders.show', [
            'order' => $manualOrder,
            'meta' => $meta,
            'submitted' => is_array($meta['submitted'] ?? null) ? $meta['submitted'] : [],
            'definition' => $definition,
            'hasResultFile' => trim((string) ($meta['result_file'] ?? '')) !== ''
                && Storage::disk('local')->exists((string) $meta['result_file']),
            'nextWaiting' => $manualOrder->status === 'pending'
                ? $this->nextWaitingRequest($manualOrder->id)
                : null,
            'waitingCount' => $this->manualServices->waitingCount(),
        ]);
    }

    /**
     * A document a customer uploaded for the job they paid for. The name of the
     * question is only a key into what this order already records, so the path
     * served is never the one in the address, and nothing outside this order can
     * be reached through it.
     */
    public function document(int $order, string $field)
    {
        $manualOrder = $this->findManualOrder($order);
        $meta = is_array($manualOrder->meta) ? $manualOrder->meta : [];

        $upload = $meta['uploads'][$field] ?? null;
        $path = is_array($upload) ? trim((string) ($upload['path'] ?? '')) : '';

        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        // The filename came from the customer's own file picker, so the characters
        // that would end the quoted string are taken out before it goes into a
        // response header.
        $name = str_replace(['"', '\\', "\r", "\n"], '', (string) ($upload['name'] ?? $field));

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    /**
     * Whether an agent has actually taken the job in hand.
     *
     * The order stays pending until a person or a document answers the customer,
     * so this records who is working the request rather than pretending the work
     * is done: no money moves, and completing or rejecting the request works
     * exactly the same way from either state.
     */
    public function workState(Request $request, int $order)
    {
        $manualOrder = $this->findManualOrder($order);

        // A finished request already carries its outcome, so there is nothing left
        // to mark: writing a work note over it would suggest somebody is still
        // working a job that has been answered.
        if ($manualOrder->status !== 'pending') {
            return back()->with('error', 'This request is already '.$manualOrder->status.', so it is not being worked on.');
        }

        $data = $request->validate([
            'state' => ['required', Rule::in(['in_progress', 'waiting'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = is_array($manualOrder->meta) ? $manualOrder->meta : [];
        $started = $data['state'] === 'in_progress';
        $wasStarted = ($meta['work_state'] ?? null) === 'in_progress';
        $title = (string) ($meta['manual_service_title'] ?? 'Identity request');

        $manualOrder->meta = array_merge($meta, [
            'work_state' => $started ? 'in_progress' : 'waiting',
            'work_state_at' => now()->toIso8601String(),
            'work_state_by' => $request->user()?->id,
            'work_note' => $started ? trim((string) ($data['note'] ?? '')) : '',
        ]);
        $manualOrder->save();

        // The customer is told once, when the wait first turns into work. Sending
        // it back through the waiting list is our bookkeeping, not news for them.
        if ($started && !$wasStarted) {
            $this->notifyCustomer(
                $manualOrder,
                $title.' is being worked on',
                'Our team has started your '.$title.' request. The result will appear on your receipt as soon as it is ready.',
                [
                    'type' => 'manual_order_started',
                    'order_id' => $manualOrder->id,
                    'manual_service' => (string) ($meta['manual_service'] ?? ''),
                    'started_at' => now()->toIso8601String(),
                ],
            );
        }

        return back()->with('success', $started
            ? 'Marked as in progress. The customer has been told work has started.'
            : 'Moved back to the waiting queue.');
    }

    public function fulfil(Request $request, int $order)
    {
        $manualOrder = $this->findManualOrder($order);

        $data = $request->validate([
            // Either an answer typed here or a document, and the customer always
            // gets one of the two.
            'result_text' => ['nullable', 'string', 'max:20000', 'required_without:result_file'],
            'result_file' => ['nullable', 'file', 'max:10240', 'required_without:result_text'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $wasWaiting = $manualOrder->status === 'pending';
        $resultText = sanitize_popup_message_html($data['result_text'] ?? '');

        $meta = is_array($manualOrder->meta) ? $manualOrder->meta : [];
        $title = (string) ($meta['manual_service_title'] ?? 'Identity request');

        if ($request->hasFile('result_file')) {
            $file = $request->file('result_file');

            // Identity documents, so the private disk: the only way to reach the
            // bytes is the ownership-checked receipt route.
            $path = $file->store('manual-results', 'local');

            $meta['result_file'] = $path;
            $meta['result_file_name'] = $file->getClientOriginalName();
            $meta['result_file_size'] = $file->getSize();
        }

        if ($resultText === '' && empty($meta['result_file'])) {
            return back()->with('error', 'Type a result or upload a document before completing this request.');
        }

        DB::beginTransaction();
        try {
            $manualOrder->status = 'success';
            $manualOrder->meta = array_merge($meta, [
                'result_text' => $resultText !== '' ? $resultText : ($meta['result_text'] ?? ''),
                'admin_note' => trim((string) ($data['admin_note'] ?? '')),
                'fulfilled_at' => now()->toIso8601String(),
                'fulfilled_by' => $request->user()?->id,
            ]);
            $manualOrder->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Manual order fulfilment failed.', [
                'order_id' => $manualOrder->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not save the result. Please try again.');
        }

        $this->notifyCustomer(
            $manualOrder,
            $title.' is ready',
            'Your '.$title.' result is ready. Open your receipt to read it or download the document.',
            [
                'type' => 'manual_order_completed',
                'order_id' => $manualOrder->id,
                'manual_service' => (string) ($meta['manual_service'] ?? ''),
                'completed_at' => now()->toIso8601String(),
            ],
        );

        return $this->continueQueue($manualOrder, $wasWaiting, 'Result published. The customer can now see it on their receipt.');
    }

    public function reject(Request $request, int $order)
    {
        $manualOrder = $this->findManualOrder($order);

        if ($manualOrder->status !== 'pending') {
            return back()->with('error', 'This request is already '.$manualOrder->status.', so it cannot be rejected.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $meta = is_array($manualOrder->meta) ? $manualOrder->meta : [];
        $title = (string) ($meta['manual_service_title'] ?? 'Identity request');
        $reason = trim((string) $data['reason']);
        $reference = (string) ($manualOrder->provider_reference ?: 'MANUAL-'.$manualOrder->id);
        $amountKobo = (int) $manualOrder->amount;

        $wallet = $manualOrder->user?->wallet;

        DB::beginTransaction();
        try {
            $manualOrder->status = 'failed';
            $manualOrder->meta = array_merge($meta, [
                'reject_reason' => $reason,
                'rejected_at' => now()->toIso8601String(),
                'rejected_by' => $request->user()?->id,
            ]);
            $manualOrder->save();

            // The customer paid up front, so a rejection is always a full refund.
            if ($amountKobo > 0 && $wallet instanceof Wallet) {
                $this->ledger->credit(
                    $wallet,
                    $amountKobo,
                    'REFUND-'.$reference,
                    'Refund for cancelled '.$title.': '.$reason,
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Manual order rejection failed.', [
                'order_id' => $manualOrder->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not reject this request. Nothing was refunded.');
        }

        $this->notifyCustomer(
            $manualOrder,
            $title.' could not be completed',
            'We could not complete your '.$title.' request. Reason: '.$reason.'. The full amount has been returned to your wallet.',
            [
                'type' => 'manual_order_rejected',
                'order_id' => $manualOrder->id,
                'manual_service' => (string) ($meta['manual_service'] ?? ''),
                'refund_kobo' => $amountKobo,
            ],
        );

        return $this->continueQueue($manualOrder, true, 'Request rejected and ₦'.number_format($amountKobo / 100, 2).' refunded to the customer.');
    }

    /**
     * Keep the admin moving down the waiting list instead of bouncing them back
     * to a page they have to scan again.
     */
    private function continueQueue(Order $handled, bool $wasWaiting, string $outcome)
    {
        if (!$wasWaiting) {
            return redirect()->route('admin.manual-orders.show', $handled->id)->with('success', $outcome);
        }

        $next = $this->nextWaitingRequest($handled->id);

        if ($next instanceof Order) {
            $remaining = $this->manualServices->waitingCount();

            return redirect()
                ->route('admin.manual-orders.show', $next->id)
                ->with('success', $outcome.' '.$remaining.' waiting '.($remaining === 1 ? 'request' : 'requests').' left — now on #'.$next->id.'.');
        }

        return redirect()
            ->route('admin.manual-orders.index', ['status' => 'success'])
            ->with('success', $outcome.' The waiting queue is now clear.');
    }

    /**
     * The next request an agent should be offered.
     *
     * A request somebody has already taken in hand is left with the person
     * working it, so the queue offers the oldest untouched one and only falls
     * back to a claimed request once everything waiting has been started. The
     * claimed ids are read out first rather than asked for as "not in progress",
     * because a request that has never been marked has no work state at all, and
     * comparing a missing value is not something both database engines answer the
     * same way.
     */
    private function nextWaitingRequest(int $exceptOrderId): ?Order
    {
        $claimed = $this->manualServices->waitingQuery()
            ->where('meta->work_state', 'in_progress')
            ->pluck('id')
            ->all();

        $untouched = $this->manualServices->waitingQuery()
            ->where('id', '!=', $exceptOrderId)
            ->whereNotIn('id', $claimed)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($untouched instanceof Order) {
            return $untouched;
        }

        return $this->manualServices->waitingQuery()
            ->where('id', '!=', $exceptOrderId)
            ->whereIn('id', $claimed)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private function normaliseStatus(string $status): string
    {
        return in_array($status, ['pending', 'in_progress', 'success', 'failed', 'all'], true)
            ? $status
            : 'pending';
    }

    private function findManualOrder(int $id): Order
    {
        $order = Order::query()->findOrFail($id);

        $meta = is_array($order->meta) ? $order->meta : [];
        abort_unless(($meta['manual_queue'] ?? false) === true, 404);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyCustomer(Order $order, string $title, string $message, array $payload): void
    {
        try {
            $user = $order->user()->first();
            if (!$user) {
                return;
            }

            $user->notify(new ManualOrderNotification(
                title: $title,
                message: $message,
                url: route('vtu.receipt', $order->id),
                payload: $payload,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to notify a customer about a manual order update.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
