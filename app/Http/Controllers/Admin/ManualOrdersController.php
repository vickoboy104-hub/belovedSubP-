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
        $query = Order::with('user')
            ->where('meta->manual_queue', true)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Waiting requests are the actionable ones, so they come first.
            $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END");
        }

        if ($request->filled('service') && $this->manualServices->find((string) $request->service)) {
            $query->where('meta->manual_service', $request->service);
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

        $orders = $query->paginate(20)->appends($request->query());

        $catalogue = $this->manualServices->catalogue();
        $counts = [
            'waiting' => Order::query()->where('meta->manual_queue', true)->where('status', 'pending')->count(),
            'completed' => Order::query()->where('meta->manual_queue', true)->where('status', 'success')->count(),
            'rejected' => Order::query()->where('meta->manual_queue', true)->where('status', 'failed')->count(),
        ];

        return view('admin.manual-orders.index', [
            'orders' => $orders,
            'catalogue' => $catalogue,
            'counts' => $counts,
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
        ]);
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

        return redirect()
            ->route('admin.manual-orders.show', $manualOrder->id)
            ->with('success', 'Result published. The customer can now see it on their receipt.');
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

        return redirect()
            ->route('admin.manual-orders.show', $manualOrder->id)
            ->with('success', 'Request rejected and ₦'.number_format($amountKobo / 100, 2).' refunded to the customer.');
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
