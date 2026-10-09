<x-app-layout>
    @php
        $meta = is_array($order->meta) ? $order->meta : [];
        $type = (string) ($meta['type'] ?? 'order');
        $serviceName = $type === 'exam'
            ? strtoupper((string) ($meta['pin_code'] ?? 'exam')).' pin'
            : \Illuminate\Support\Str::headline($type);
        $statusClasses = $order->status === 'success'
            ? 'bg-emerald-50 text-emerald-700'
            : ($order->status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');

        // What the provider answered with, if anything, so the owner can tell
        // whether he is correcting the API or filling in a card he bought by hand.
        $captured = \App\Support\IssuedKeys::fromResponses($order);
        $saved = $meta['keys'] ?? null;

        $rows = old('keys', $keys !== [] ? $keys : [['label' => 'Serial Number', 'value' => ''], ['label' => 'PIN', 'value' => '']]);
    @endphp

    <x-page-hero class="reference-shared-banner"
                 title="Keys for order #{{ $order->id }}"
                 subtitle="{{ $serviceName }} — {{ $order->user?->name ?? 'Unknown customer' }} ({{ $order->user?->email ?? 'no email' }})">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.orders', ['type' => $type]) }}" class="reference-hero-action">Back to orders</a>
            <a href="{{ route('vtu.receipt', $order->id) }}" class="reference-hero-action">See the customer's receipt</a>
        </div>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-3xl space-y-6">
        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-700">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm font-bold text-rose-700">
                {{ session('error') }}
            </div>
        @endif

        <section class="app-section grid gap-3 p-5 sm:grid-cols-4 sm:p-6">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Status</div>
                <div class="mt-2">
                    <span class="rounded-xl px-3 py-1 text-xs font-bold {{ $statusClasses }}">{{ strtoupper($order->status) }}</span>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Paid</div>
                <div class="amount-fit mt-2 text-xl font-extrabold text-slate-900">&#8358;{{ number_format($order->amount / 100, 2) }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Reference</div>
                <div class="mt-2 break-all text-sm font-bold text-slate-900">{{ $order->provider_reference ?: '—' }}</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Date</div>
                <div class="mt-2 text-sm font-bold text-slate-900">{{ $order->created_at->format('d M Y, h:i A') }}</div>
            </div>
        </section>

        <section class="app-section p-5 sm:p-6"
                 x-data="{
                    rows: {{ json_encode(array_values(array_map(static fn ($row) => [
                        'label' => (string) ($row['label'] ?? ''),
                        'value' => (string) ($row['value'] ?? ''),
                    ], is_array($rows) ? $rows : []))) }},
                    max: {{ \App\Support\IssuedKeys::MAX_KEYS }},
                    add() {
                        if (this.rows.length >= this.max) return;
                        this.rows.push({ label: '', value: '' });
                    },
                    remove(index) {
                        this.rows.splice(index, 1);
                    },
                 }">
            <h2 class="text-xl font-extrabold text-slate-900">Issue the keys</h2>
            <p class="mt-1 text-sm text-slate-500">
                Whatever you save here is what the customer sees on their receipt, where they can copy, download and print it
                as many times as they like. Saving replaces every key already on this order and notifies the customer.
            </p>

            @if($saved !== null)
                <p class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    These keys were issued by hand{{ !empty($meta['keys_issued_at']) ? ' on '.\Illuminate\Support\Carbon::parse($meta['keys_issued_at'])->format('d M Y, h:i A') : '' }}.
                </p>
            @elseif($captured !== [])
                <p class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    The provider already answered with {{ count($captured) }} key(s) —
                    @foreach($captured as $row)
                        <span class="font-bold">{{ $row['label'] }}</span>@if(! $loop->last), @endif
                    @endforeach.
                    Saving here overwrites them, so only change it if the card was wrong.
                </p>
            @elseif($order->status === 'success')
                <p class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    This order was paid for and marked successful, but no key ever arrived from the provider.
                    The customer has nothing to use — type the card they bought.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.orders.keys.store', $order->id) }}" class="mt-5 space-y-4">
                @csrf

                <template x-for="(row, index) in rows" :key="index">
                    <div class="grid gap-3 sm:grid-cols-[160px_minmax(0,1fr)_auto] sm:items-end">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">What it is</span>
                            <input type="text" maxlength="40" class="input-field mt-2"
                                   :name="'keys[' + index + '][label]'"
                                   x-model="row.label"
                                   placeholder="Serial Number" />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">The key</span>
                            <input type="text" maxlength="{{ \App\Support\IssuedKeys::MAX_LENGTH }}" class="input-field mt-2 font-mono"
                                   :name="'keys[' + index + '][value]'"
                                   x-model="row.value"
                                   placeholder="012345678901234" />
                        </label>
                        <button type="button" class="btn-outline justify-center" @click="remove(index)" x-show="rows.length > 1">
                            Remove
                        </button>
                    </div>
                </template>

                <button type="button" class="btn-outline justify-center" @click="add()" :disabled="rows.length >= max">
                    Add another key
                </button>

                <button type="submit" class="btn-primary w-full justify-center">
                    Save keys and notify the customer
                </button>

                <p class="text-xs text-slate-500">
                    Up to {{ \App\Support\IssuedKeys::MAX_KEYS }} keys, {{ \App\Support\IssuedKeys::MAX_LENGTH }} characters each.
                    The key values are never mailed or stored in the notification — the notice only points at the receipt.
                </p>
            </form>
        </section>
    </div>
</x-app-layout>
