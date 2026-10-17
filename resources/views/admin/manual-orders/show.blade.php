<x-app-layout>
    @php
        $title = (string) ($meta['manual_service_title'] ?? 'Identity request');
        $resultText = trim((string) ($meta['result_text'] ?? ''));
        $resultFileName = trim((string) ($meta['result_file_name'] ?? ''));

        // The customer's form labels are full instructions ("Enter the NIN
        // Number"), which read badly beside a value, so the admin sees the short
        // name of the same field that the history table uses.
        $labels = [];
        $choices = [];
        foreach ($definition['fields'] ?? [] as $field) {
            $labels[$field['name']] = $field['column'] ?? $field['label'];
            $choices[$field['name']] = $field['options'] ?? [];
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

        // Who holds the request is a column, not a note in the JSON, so two agents
        // acting on the same page at the same moment cannot both believe it is theirs.
        $holder = $order->status === 'pending' && $order->assigned_to !== null;
        $isMine = $holder && (int) $order->assigned_to === (int) (auth()->id() ?? 0);
        $holderName = $isMine ? 'You' : (trim((string) ($order->assignee?->name ?? '')) ?: 'Another agent');
        $workNote = trim((string) ($meta['work_note'] ?? ''));
        $assignedAt = $holder ? $order->assigned_at : null;
        $handovers = array_reverse(array_values(is_array($meta['work_history'] ?? null) ? $meta['work_history'] : []));
        $statusLabel = $order->status === 'pending' ? ($holder ? 'IN PROGRESS' : 'WAITING') : strtoupper($order->status);
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
                            @if($value !== null && $value !== '' && is_scalar($value))
                                <div class="rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                                    <dt class="app-record-label">{{ $labels[$key] ?? \Illuminate\Support\Str::headline((string) $key) }}</dt>
                                    <dd class="app-record-value break-words">{{ (string) ($choices[$key][$value] ?? $value) }}</dd>
                                </div>
                            @endif
                        @empty
                            <div class="text-sm text-slate-500">Nothing was submitted.</div>
                        @endforelse
                    </dl>

                    @if(!empty($meta['uploads']) && is_array($meta['uploads']))
                        <h3 class="mt-6 text-base font-extrabold text-slate-900">Documents the customer uploaded</h3>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            @foreach($meta['uploads'] as $field => $upload)
                                <div class="flex min-w-0 items-center justify-between gap-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                                    <div class="min-w-0">
                                        <div class="app-record-label">{{ $labels[$field] ?? \Illuminate\Support\Str::headline((string) $field) }}</div>
                                        <div class="app-record-value break-all">{{ $upload['name'] ?? 'document' }}</div>
                                    </div>
                                    <a href="{{ route('admin.manual-orders.document', ['order' => $order->id, 'field' => $field]) }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="reference-quiet-button shrink-0">Open</a>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($filing !== null && $filing->state !== \App\Models\DocumentFiling::FILED)
                        @if($filing->state === \App\Models\DocumentFiling::LOST)
                            <div class="mt-6 rounded-[18px] border border-slate-200 bg-rose-50 p-4">
                                <h3 class="text-base font-extrabold text-rose-800">The customer paid, and this document never reached the order</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    The money has been taken and no refund has been booked. Ask the customer to send
                                    the document again, or reject the request to return the money. Do not mark this
                                    request complete while its paper is missing.
                                </p>
                            </div>
                        @elseif($filing->state === \App\Models\DocumentFiling::ABANDONED)
                            <div class="mt-6 rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                                <h3 class="text-base font-extrabold text-slate-900">The document was thrown away when this request was reversed</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    The charge was returned to the customer's wallet, so the held paper was deleted.
                                    Nothing here needs filing.
                                </p>
                            </div>
                        @elseif($filing->state === \App\Models\DocumentFiling::RESOLVED)
                            <div class="mt-6 rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                                <h3 class="text-base font-extrabold text-slate-900">This case was settled, so the held paper is no longer protected</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    An agent closed the request, so anything still sitting in the holding area for it
                                    is cleared with the stale folders.
                                </p>
                            </div>
                        @else
                            <div class="mt-6 rounded-[18px] border border-amber-200 bg-amber-50 p-4">
                                <h3 class="text-base font-extrabold text-amber-800">Paid, and the document is still being attached</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    A scheduled recovery files the customer's paper onto this order by itself. Keep
                                    working the request only once it appears above.
                                </p>
                            </div>
                        @endif

                        @if(!empty($filing->last_error))
                            <p class="mt-2 break-words text-xs text-slate-500">{{ $filing->last_error }} (pass {{ $filing->attempts }})</p>
                        @endif
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
                            @if($order->status === 'pending')
                                Type the answer, attach a document, or do both. Saving marks the request complete and notifies the customer.
                            @else
                                This result is already published. Saving corrects what the customer sees on their receipt, without telling them twice.
                            @endif
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

                            @include('admin.manual-orders._override-reason')

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

                <section class="app-section p-5 sm:p-6">
                    <h2 class="text-lg font-extrabold text-slate-900">
                        {{ $order->status === 'pending' ? 'Who is working this?' : 'Who worked this?' }}
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        @if($order->status === 'pending')
                            @if($holder)
                                <span class="font-bold">{{ $holderName }}</span> {{ $isMine ? 'hold' : 'holds' }} this request{{ $assignedAt ? ', since '.$assignedAt->format('d M Y, h:i A') : '' }}.
                                It stays with them until a result is published or it is handed back.
                            @else
                                Nobody has picked this up yet. Taking it tells the other agents to leave it alone, and tells the customer work has started.
                            @endif
                        @elseif($order->assigned_to !== null)
                            <span class="font-bold">{{ $holderName }}</span> held this request until it was {{ $order->status }}{{ $assignedAt ? ', from '.$assignedAt->format('d M Y, h:i A') : '' }}.
                        @else
                            This request was answered without anybody taking it in hand first.
                        @endif
                    </p>

                    @if($order->status === 'pending')
                        @if($workNote !== '')
                            <div class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                                <div class="app-record-label">Work note (admin only)</div>
                                <div class="app-record-value">{{ $workNote }}</div>
                            </div>
                        @endif

                        <form method="POST"
                              action="{{ route('admin.manual-orders.claim', $order->id) }}"
                              class="mt-4 space-y-3">
                            @csrf

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">
                                    {{ $holder && !$isMine ? 'Why does this need to move? (required, never shown to the customer)' : 'Note for the next agent (never shown to the customer)' }}
                                </span>
                                <input type="text" name="note" maxlength="500" value="{{ old('note', $workNote) }}"
                                       placeholder="e.g. NIMC portal opened, waiting on the OTP."
                                       class="input-field mt-2" />
                            </label>

                            @if(!$holder)
                                <button type="submit" name="action" value="take" class="btn-primary w-full justify-center">
                                    Take this request
                                </button>
                            @elseif($isMine)
                                <button type="submit" name="action" value="take" class="btn-primary w-full justify-center">
                                    Save the work note
                                </button>
                                <button type="submit" name="action" value="release" class="btn-outline w-full justify-center">
                                    Release back to the waiting queue
                                </button>
                            @else
                                <button type="submit" name="action" value="take_over" class="btn-primary w-full justify-center">
                                    Take over from {{ $holderName }}
                                </button>
                                <button type="submit" name="action" value="release" class="btn-outline w-full justify-center">
                                    Release it for them
                                </button>
                            @endif
                        </form>
                    @endif

                    @if(count($handovers) > 0)
                        <h3 class="mt-5 text-sm font-extrabold text-slate-900">Changes of hand</h3>
                        <ul class="mt-2 space-y-2">
                            @foreach($handovers as $event)
                                @php
                                    $verb = match ((string) ($event['action'] ?? '')) {
                                        'take' => ($event['from'] ?? null) === null ? 'took it on' : 'picked it up again',
                                        'release' => !empty($event['from_name']) && $event['from_name'] !== ($event['by_name'] ?? '')
                                            ? 'released it from '.$event['from_name']
                                            : 'released it back to the queue',
                                        'take_over' => 'took it over from '.($event['from_name'] ?? 'another agent'),
                                        'answered_over' => 'answered it over '.($event['from_name'] ?? 'another agent')."'s hold",
                                        default => (string) ($event['action'] ?? 'changed it'),
                                    };
                                    $movedAt = empty($event['at']) ? '' : \Illuminate\Support\Carbon::parse($event['at'])->format('d M Y, h:i A');
                                    $why = trim((string) ($event['note'] ?? ''));
                                @endphp
                                <li class="rounded-[18px] border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                                    <span class="font-bold text-slate-800">{{ $event['by_name'] ?? 'An agent' }}</span>
                                    {{ $verb }}
                                    @if($movedAt !== '')
                                        · {{ $movedAt }}
                                    @endif
                                    @if($why !== '')
                                        <div class="mt-1 text-slate-500">{{ $why }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if($order->status === 'pending')
                    <section class="app-section border-rose-200 p-5 sm:p-6">
                        <h2 class="text-lg font-extrabold text-rose-800">Cannot complete it?</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            Rejecting refunds the full &#8358;{{ number_format($order->amount / 100, 2) }} to the customer's wallet and tells them why.
                        </p>

                        @php
                            $rejectDetails = [
                                'Request' => '#'.$order->id.' '.$title,
                                'Customer' => $order->user?->name ?? '-',
                                'Refund' => '₦'.number_format($order->amount / 100, 2),
                                'Reason' => '',
                            ];
                        @endphp

                        <form method="POST"
                              id="rejectOrderForm"
                              action="{{ route('admin.manual-orders.reject', $order->id) }}"
                              class="mt-4 space-y-3"
                              data-confirm-sheet="confirmRejectOrder"
                              data-confirm-details='{{ json_encode($rejectDetails) }}'>
                            @csrf

                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Reason the customer will see</span>
                                <textarea name="reason" rows="3" maxlength="1000" required
                                          placeholder="e.g. The tracking ID could not be found at NIMC."
                                          class="input-field mt-2">{{ old('reason') }}</textarea>
                            </label>

                            @include('admin.manual-orders._override-reason')

                            <button type="submit" class="btn-danger w-full justify-center">
                                Reject and refund
                            </button>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>

    <x-confirm-modal id="confirmRejectOrder" title="Reject and refund?" confirmText="Reject and refund" :danger="true" />

    <script>
        (function () {
            const form = document.getElementById('rejectOrderForm');
            const reason = form ? form.querySelector('textarea[name="reason"]') : null;
            if (!reason) return;

            // The sheet reads its rows off the form at submit time, so the reason
            // typed a second ago is the reason the admin is asked to confirm.
            form.addEventListener('input', function () {
                let details = {};
                try {
                    details = JSON.parse(form.getAttribute('data-confirm-details') || '{}');
                } catch (error) {
                    details = {};
                }
                details.Reason = reason.value.trim();
                form.setAttribute('data-confirm-details', JSON.stringify(details));
            });
        })();
    </script>
</x-app-layout>
