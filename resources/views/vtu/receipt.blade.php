<x-app-layout>
    @php
        $amountN = number_format(((int) $order->amount) / 100, 2);
        $meta = $order->meta ?? [];
        $discountKobo = (int) ($meta['discount_kobo'] ?? 0);
        $profitN = number_format($discountKobo / 100, 2);
        $feeN = number_format((float) ($meta['markup_naira'] ?? 0), 2);
        $type = $meta['type'] ?? 'order';
        $balanceBeforeN = $balanceBeforeKobo !== null ? number_format($balanceBeforeKobo / 100, 2) : null;
        $balanceAfterN = $balanceAfterKobo !== null ? number_format($balanceAfterKobo / 100, 2) : null;

        $token = $meta['token'] ?? null;
        $pin = $meta['pin'] ?? null;

        $siteName = site_name();
        $support = whatsapp_link();
        $providerLabel = 'BelovedSubP-G';
    @endphp

    <style>
        @media print {
            header,
            aside,
            nav,
            .no-print {
                display: none !important;
            }

            main {
                padding: 0 !important;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
            }

            .print-card {
                box-shadow: none !important;
            }
        }
    </style>

    <x-page-hero class="reference-shared-banner no-print" title="Receipt" subtitle="Transaction summary and provider details.">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('vtu.orders') }}" class="reference-hero-action">Back</a>
            <a href="{{ $buyAgainUrl ?? route('dashboard') }}" class="reference-hero-action">Buy Again</a>
            <a href="{{ route('home') }}" class="reference-hero-action">Home</a>
            <button type="button" onclick="downloadReceipt()" class="reference-hero-action">Download</button>
            <button type="button" onclick="window.print()" class="reference-hero-action reference-hero-accent">Print</button>
        </div>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-3xl space-y-5">
        <div class="rounded-3xl p-6 border border-gray-200 bg-white print-card">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-sm text-gray-500
                    <div class="text-xl font-extrabold">{{ $siteName }}</div>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-500
                    <div class="text-sm font-extrabold">
                        <span class="px-3 py-1 rounded-full
                            @if($order->status === 'success') bg-green-500/15 text-green-700 border border-green-500/25
                            @elseif($order->status === 'failed') bg-red-500/15 text-red-700 border border-red-500/25
                            @else bg-yellow-500/15 text-yellow-800 border border-yellow-500/25
                            @endif">
                            {{ strtoupper($order->status ?? 'pending') }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Order ID</div>
                    <div class="font-extrabold">{{ $order->id }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Date</div>
                    <div class="font-extrabold">{{ optional($order->created_at)->format('d M, Y h:ia') }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Service</div>
                    <div class="font-extrabold capitalize">{{ str_replace('_', ' ', $type) }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Customer Ref</div>
                    <div class="font-extrabold">{{ $order->customer_ref }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Amount Charged</div>
                    <div class="font-extrabold">&#8358;{{ $amountN }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fee</div>
                    <div class="font-extrabold">&#8358;{{ $feeN }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Initial Balance</div>
                    <div class="font-extrabold">
                        @if($balanceBeforeN !== null)
                            &#8358;{{ $balanceBeforeN }}
                        @else
                            N/A
                        @endif
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Final Balance</div>
                    <div class="font-extrabold">
                        @if($balanceAfterN !== null)
                            &#8358;{{ $balanceAfterN }}
                        @else
                            N/A
                        @endif
                    </div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Discount</div>
                    <div class="font-extrabold">&#8358;{{ $profitN }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Provider</div>
                    <div class="font-extrabold">{{ $providerLabel }}</div>
                </div>

                <div class="rounded-xl bg-slate-50 border border-gray-200 p-4 col-span-2">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Provider Ref</div>
                    <div class="font-extrabold break-words">{{ $order->provider_reference }}</div>
                </div>
            </div>

            @if($token)
                <div class="mt-5 rounded-2xl border border-green-500/25 bg-green-500/10 p-4">
                    <div class="text-sm text-green-700 font-extrabold">Electricity Token</div>
                    <div class="mt-1 font-extrabold break-words">{{ $token }}</div>
                </div>
            @endif

            @if($pin)
                <div class="mt-5 rounded-2xl border border-green-500/25 bg-green-500/10 p-4">
                    <div class="text-sm text-green-700 font-extrabold">Exam PIN</div>
                    <div class="mt-1 font-extrabold break-words">{{ $pin }}</div>
                </div>
            @endif

            <div class="mt-6 flex items-center justify-between gap-3 text-sm">
                <div class="text-gray-500
                    Need help?
                    <a class="text-green-700 hover:text-green-600 font-extrabold"
                       href="{{ $support }}"
                       target="_blank">Chat WhatsApp -></a>
                </div>
                <div class="text-gray-400 text-xs">
                    Keep this receipt for your record.
                </div>
            </div>
        </div>
    </div>

    <script>
        function downloadReceipt() {
            const originalTitle = document.title;
            document.title = 'Receipt-{{ $order->id }}';
            window.print();
            setTimeout(() => {
                document.title = originalTitle;
            }, 1000);
        }
    </script>
</x-app-layout>
