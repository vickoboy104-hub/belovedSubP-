<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Notifications\ManualOrderNotification;
use App\Services\AdminActivityRecorder;
use App\Support\IssuedKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrdersController extends Controller
{
    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $query = Order::with('user')->latest();

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
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('meta->type', $request->type);
        }

        $orders = $query->paginate(20)->appends($request->query());

        return view('admin.orders', compact('orders'));
    }

    /** Where the owner types the serial and PIN of a card he bought by hand. */
    public function keys(int $order)
    {
        $issuedOrder = Order::query()->findOrFail($order);
        $issuedOrder->load('user');

        // This screen shows live card credentials. Opening it leaves no other
        // trace, so the view itself is the event worth recording - never the
        // values on show.
        $this->audit->recordQuietly('success', 'order', 'order.keys_viewed', 'Opened the card keys screen for an order.', [
            'target' => ['order', $issuedOrder->id],
            'changes' => ['key_count' => count(IssuedKeys::forOrder($issuedOrder))],
            'throttle_seconds' => 60,
        ]);

        return view('admin.order-keys', [
            'order' => $issuedOrder,
            'keys' => IssuedKeys::forOrder($issuedOrder),
        ]);
    }

    public function storeKeys(Request $request, int $order)
    {
        $issuedOrder = Order::query()->findOrFail($order);

        // The form always shows a spare blank line for the next card, so a row
        // with nothing in it is nobody typing yet rather than a mistake to shout
        // about. Only the rows that carry a key are validated.
        $rows = array_values(array_filter(
            (array) $request->input('keys', []),
            static fn ($row) => is_array($row) && trim((string) ($row['value'] ?? '')) !== '',
        ));

        $data = Validator::make(['keys' => $rows], [
            'keys' => ['required', 'array', 'min:1', 'max:'.IssuedKeys::MAX_KEYS],
            'keys.*.label' => ['nullable', 'string', 'max:40'],
            'keys.*.value' => ['required', 'string', 'max:'.IssuedKeys::MAX_LENGTH],
        ])->validate();

        $keys = IssuedKeys::normalize($data['keys']);

        $meta = is_array($issuedOrder->meta) ? $issuedOrder->meta : [];
        $hadKeys = IssuedKeys::forOrder($issuedOrder) !== [];

        $issuedOrder->meta = array_merge($meta, [
            'keys' => $keys,
            'keys_issued_at' => now()->toIso8601String(),
            'keys_issued_by' => $request->user()?->id,
        ]);
        $issuedOrder->save();

        // The keys themselves stay out of the trail: a card serial and PIN are
        // exactly what this table must never hold. How many were written, and
        // whether they replaced a set the customer already had, is the part a
        // later reader needs.
        $this->audit->recordQuietly('success', 'order', 'order.keys_issued', 'Saved '.count($keys).' card key(s) against an order.'.($hadKeys ? ' Replaced the keys already there.' : ''), [
            'target' => ['order', $issuedOrder->id],
            'changes' => ['key_count' => count($keys), 'replaced_existing' => $hadKeys],
        ]);

        $this->notifyCustomer(
            $issuedOrder,
            $hadKeys ? 'Your keys were updated' : 'Your keys are ready',
            $hadKeys
                ? 'The keys on your receipt were replaced. Open your receipt to copy, download and print the current ones.'
                : 'Your keys are ready. Open your receipt to copy, download and print them.',
            [
                'type' => 'order_keys_issued',
                'order_id' => $issuedOrder->id,
                'issued_at' => now()->toIso8601String(),
            ],
        );

        return redirect()
            ->route('admin.orders.keys', $issuedOrder->id)
            ->with('success', 'Saved. The customer can now see '.count($keys).' key(s) on their receipt.');
    }

    /**
     * The notice says where to read the keys and never repeats them: a
     * notification is stored, mailed and screened over shoulders, a receipt is
     * behind a login that belongs to one person.
     */
    private function notifyCustomer(Order $order, string $title, string $message, array $payload): void
    {
        $user = $order->user()->first();

        if (!$user) {
            Log::warning('Could not notify anyone about issued keys - the order has no owner.', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $user->notify(new ManualOrderNotification(
            title: $title,
            message: $message,
            url: route('vtu.receipt', $order->id),
            payload: $payload,
        ));
    }
}
