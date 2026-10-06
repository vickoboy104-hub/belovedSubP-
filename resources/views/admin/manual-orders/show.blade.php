<x-app-layout>
    @php
        $title = (string) ($meta['manual_service_title'] ?? 'Identity request');
        $resultText = trim((string) ($meta['result_text'] ?? ''));
        $resultFileName = trim((string) ($meta['result_file_name'] ?? ''));

        // Labels come from the catalogue so the admin reads the same wording the
        // customer saw on the form.
        $labels = [];
        foreach ($definition['fields'] ?? [] as $field) {
            $labels[$field['name']] = $field['label'];
        }

        $expectedBy = null;
        if (!empty($meta['expected_by'])) {
            try {
                $expectedBy = \Illuminate\Support\Carbon::parse((string) $meta['expected_by']);
            } catch (\Throwable $e) {
                $expectedBy = null;
            }
        }

        $overdue = $order->status === 'pending' && $expectedBy && $expectedBy->isPast();
        $statusLabel = $order->status === 'pending' ? 'WAITING' : strtoupper($order->status);
        $statusClasses = $order->status === 'success'
            ? 'bg-emerald-50 text-emerald-700'
            : ($order->status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');
    @endphp

    <x-page-hero class="reference-shared-banner"
                 title="#{{ $order->id }} {{ $title }}"
                 subtitle="{{ $order->user?->name ?? 'Unknown customer' }} — {{ $order->user?->email ?? 'no email' }}">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.manual-orders.index', ['status' => 'pending']) }}" class="reference-hero-action">Back to waiting queue</a>
            @if($nextWaiting)
                <a href="{{ route('admin.manual-orders.show', $nextWaiting->id) }}" class="reference-hero-action">
                    Skip to next ({{ $waitingCount }} waiting)
                </a>
            @endif
        </div>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
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

        <section class="app-section grid gap-3 p-5 sm:grid-cols-4 sm:p-6">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Status</div>
                <div class="mt-1">
                    <span class="rounded-xl px-3 py-1 text-xs font-bold {{ $statusClasses }}">{{ $statusLabel }}</span>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Paid</div>
                <div class="amount-fit mt-2 text-xl font-extrabold text-slate-900">&#8358;{{ number_format($order->amount / 100, 2) }}</div>
                @if(!empty($meta['discount_percent']) && (float) $meta['discount_percent'] > 0)
                    <div class="mt-1 text-xs text-slate-500">{{ $meta['discount_percent'] }}% discount applied</div>
                @endif
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="app-record-label">Submitted</div>
                <div class="mt-2 text-sm font-bold text-slate-900">{{ $order->created_at->format('d M Y, h:i A') }}</div>
            </div>
            <div class="rounded-2xl border {{ $overdue ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50' }} p-4">
                <div class="app-record-label">Promised by</div>
                <div class="mt-2 text-sm font-bold {{ $overdue ? 'text-rose-700' : 'text-slate-900' }}">
                    {{ $expectedBy ? $expectedBy->format('d M Y, h:i A') : '—' }}
                </div>
                @if($overdue)<div class="mt-1 text-xs font-bold text-rose-600">Overdue — the customer is waiting.</div>@endif
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
            <div class="space-y-6">
                <section class="app-section p-5 sm:p-6">
                    <h2 class="text-xl font-extrabold text-slate-900">What the customer submitted</h2>
                    <p class="mt-1 text-sm text-slate-500">Reference: <span class="break-all font-semibold">{{ $order->provider_reference }}</span></p>

                    <dl class="mt-5 grid gap-4 md:grid-cols-2">
                        @forelse($submitted as $key => $value)
                            @if($value !== null && $value !== '')
                                <div class="rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                                    <dt class="app-record-label">{{ $labels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                    <dd class="app-record-value break-words">{{ $value }}</dd>
                                </div>
                            @endif
                        @empty
                            <div class="text-sm text-slate-500">Nothing was submitted.</div>
                        @endforelse
                    </dl>

                    @if(!empty($meta['notes']))
                        <div class="mt-4 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                            <div class="app-record-label">Notes</div>
                            <div class="app-record-value">{{ $meta['notes'] }}</div>
                        </div>
                    @endif
                </section>

                @if($order->status === 'success')
                    <section class="app-section border-emerald-200 p-5 sm:p-6">
                        <h2 class="text-xl font-extrabold text-emerald-800">Result sent to the customer</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Completed {{ !empty($meta['fulfilled_at']) ? \Illuminate\Support\Carbon::parse($meta['fulfilled_at'])->format('d M Y, h:i A') : '' }}.
                            The customer sees this on their receipt page.
                        </p>

                        @if($resultText !== '')
                            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-7 text-slate-700">
                                {!! sanitize_popup_message_html($resultText) !!}
                            </div>
                        @endif

                        @if($hasResultFile)
                            <div class="mt-4 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="min-w-0">
                                    <div class="app-record-label">Document</div>
                                    <div class="app-record-value break-all">{{ $resultFileName ?: 'result file' }}</div>
                                </div>
                                <a href="{{ route('vtu.receipt', $order->id) }}" class="btn-outline ml-auto">Open customer receipt</a>
                            </div>
                        @endif

                        @if(!empty($meta['admin_note']))
                            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                <div class="app-record-label">Internal note (admin only)</div>
                                <div class="app-record-value">{{ $meta['admin_note'] }}</div>
                            </div>
                        @endif
                    </section>
                @endif

                @if($order->status === 'failed')
                    <section class="app-section border-rose-200 p-5 sm:p-6">
                        <h2 class="text-xl font-extrabold text-rose-800">Rejected and refunded</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Reason given to the customer: {{ $meta['reject_reason'] ?? '—' }}
                        </p>
                        <p class="mt-1 text-sm text-slate-500">
                            &#8358;{{ number_format($order->amount / 100, 2) }} was returned to their wallet
                            {{ !empty($meta['rejected_at']) ? 'on '.\Illuminate\Support\Carbon::parse($meta['rejected_at'])->format('d M Y, h:i A') : '' }}.
                        </p>
                    </section>
                @endif

                @if($order->status !== 'failed')
                    <section class="app-section p-5 sm:p-6">
                        <h2 class="text-xl font-extrabold text-slate-900">
                            {{ $order->status === 'pending' ? 'Publish the result' : 'Update the result' }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Type the answer, attach a document, or do both. Saving marks the request complete and notifies the customer.
                        </p>

                        <form method="POST"
                              action="{{ route('admin.manual-orders.fulfil', $order->id) }}"
                              enctype="multipart/form-data"
                              class="mt-5 space-y-4">
                            @csrf

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Result text</span>
                                <textarea name="result_text"
                                          rows="8"
                                          maxlength="20000"
                                          placeholder="Type the result the customer should see. Basic formatting (bold, italic, lists, line breaks) is kept."
                                          class="input-field mt-2">{{ old('result_text', strip_tags($resultText)) }}</textarea>
                                <span class="mt-1 block text-xs text-slate-500">Anything but a plain paragraph, bold, italic, underline, strike and lists is stripped for safety.</span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Result document (PDF, image, ...)</span>
                                <input type="file" name="result_file" class="input-field mt-2" />
                                <span class="mt-1 block text-xs text-slate-500">
                                    Max 10 MB. Stored privately — only the customer who owns this request can download it.
                                    @if($hasResultFile) Currently attached: {{ $resultFileName ?: 'result file' }}. Uploading replaces it. @endif
                                </span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Internal note (never shown to the customer)</span>
                                <input type="text" name="admin_note" maxlength="1000" value="{{ old('admin_note', $meta['admin_note'] ?? '') }}" class="input-field mt-2" />
                            </label>

                            <button type="submit" class="btn-primary w-full justify-center">
                                {{ $order->status === 'pending' ? 'Complete request and notify customer' : 'Save updated result' }}
                            </button>

                            @if($order->status === 'pending')
                                <p class="text-xs text-slate-500">
                                    {{ $nextWaiting
                                        ? 'Saving publishes the result and opens the next waiting request (#'.$nextWaiting->id.').'
                                        : 'Saving publishes the result. This is the last waiting request.' }}
                                </p>
                            @endif
                        </form>
                    </section>
                @endif
            </div>

            <div class="space-y-6">
                <section class="app-section p-5 sm:p-6">
                    <h2 class="text-lg font-extrabold text-slate-900">Customer</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        <div>
                            <div class="app-record-label">Name</div>
                            <div class="app-record-value">{{ $order->user?->name ?? 'Unknown' }}</div>
                        </div>
                        <div>
                            <div class="app-record-label">Email</div>
                            <div class="app-record-value break-all">{{ $order->user?->email ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="app-record-label">Phone</div>
                            <div class="app-record-value">{{ $order->user?->phone ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="app-record-label">Service</div>
                            <div class="app-record-value">{{ $title }}</div>
                        </div>
                    </div>

                    @if($order->user)
                        <a href="{{ route('admin.users.show', $order->user) }}" class="btn-outline mt-4 w-full justify-center">Open customer profile</a>
                    @endif
                    <a href="{{ route('vtu.receipt', $order->id) }}" class="btn-outline mt-2 w-full justify-center">See the customer's receipt</a>
                </section>

                @if($order->status === 'pending')
                    <section class="app-section border-rose-200 p-5 sm:p-6">
                        <h2 class="text-lg font-extrabold text-rose-800">Cannot complete it?</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Rejecting refunds the full &#8358;{{ number_format($order->amount / 100, 2) }} to the customer's wallet and tells them why.
                        </p>

                        <form method="POST" action="{{ route('admin.manual-orders.reject', $order->id) }}" class="mt-4 space-y-3">
                            @csrf

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Reason the customer will see</span>
                                <textarea name="reason" rows="3" maxlength="1000" required
                                          placeholder="e.g. The tracking ID could not be found at NIMC."
                                          class="input-field mt-2">{{ old('reason') }}</textarea>
                            </label>

                            <button type="submit" class="btn-danger w-full justify-center"
                                    onclick="return confirm('Reject this request and refund ₦{{ number_format($order->amount / 100, 2) }}?');">
                                Reject and refund
                            </button>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
