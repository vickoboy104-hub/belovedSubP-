<x-app-layout>
    <x-page-hero class="reference-shared-banner"
        title="Announcements"
        subtitle="Write one message and deliver it to every registered account." />

    <div class="reference-flow-page mx-auto max-w-4xl space-y-6">
        @unless($mailReady)
            <section class="app-note-card is-unread">
                <span class="app-flag app-flag-unread">Mail not connected</span>
                <p class="mt-2 text-sm opacity-80">
                    This server is sending through the <strong>{{ $mailMailer }}</strong> mail driver, so email
                    would be written to the log instead of reaching anyone. In-app delivery still works.
                    Point <code>MAIL_MAILER</code> at a real transport in <code>.env</code> to switch email on.
                </p>
            </section>
        @endunless

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Who receives it</p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="app-section-muted p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Accounts</p>
                    <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">{{ number_format($totalUsers) }}</p>
                </div>
                <div class="app-section-muted p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">With email</p>
                    <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">{{ number_format($usersWithEmail) }}</p>
                </div>
                <div class="app-section-muted p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">With phone</p>
                    <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">{{ number_format($usersWithPhone) }}</p>
                </div>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Compose</p>

            <form id="broadcastForm"
                  method="POST"
                  action="{{ route('admin.broadcast.send') }}"
                  data-total="{{ $totalUsers }}"
                  class="space-y-4">
                @csrf

                <div>
                    <label for="broadcast_title" class="text-sm font-bold text-slate-700">Headline</label>
                    <input type="text" id="broadcast_title" name="title" maxlength="120" required
                           value="{{ old('title') }}"
                           placeholder="Service window on Friday"
                           class="input-field mt-1">
                </div>

                <div>
                    <label for="broadcast_message" class="text-sm font-bold text-slate-700">Message</label>
                    <textarea id="broadcast_message" name="message" rows="6" maxlength="2000" required
                              placeholder="What is changing, who it affects, and what the customer should do."
                              class="input-field mt-1">{{ old('message') }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        <span id="broadcastCount">0</span>/2000 characters.
                    </p>
                </div>

                <fieldset>
                    <legend class="text-sm font-bold text-slate-700">Delivery</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-3">
                            <span>
                                <span class="block text-sm font-bold text-slate-700">In-app inbox</span>
                                <span class="block text-xs text-slate-500">Every account sees it under Notifications.</span>
                            </span>
                            <input type="checkbox" name="channels[]" value="database" checked
                                   class="h-5 w-5 shrink-0 rounded border-gray-300 bg-white">
                        </label>

                        <label class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-3">
                            <span>
                                <span class="block text-sm font-bold text-slate-700">Email</span>
                                <span class="block text-xs text-slate-500">
                                    {{ $mailReady ? 'Sends to the '.$usersWithEmail.' accounts with an address.' : 'Needs a real mail driver on this server.' }}
                                </span>
                            </span>
                            <input type="checkbox" name="channels[]" value="mail" @disabled(!$mailReady)
                                   class="h-5 w-5 shrink-0 rounded border-gray-300 bg-white">
                        </label>
                        <label class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-3">
                            <span>
                                <span class="block text-sm font-bold text-slate-700">SMS</span>
                                <span class="block text-xs text-slate-500">
                                    {{ $smsReady
                                        ? $smsGateway.': sends to the '.$usersWithPhone.' accounts with a phone number.'
                                        : 'Needs a gateway under Admin Settings → SMS Gateway.' }}
                                </span>
                            </span>
                            <input type="checkbox" name="channels[]" value="sms" @disabled(!$smsReady)
                                   class="h-5 w-5 shrink-0 rounded border-gray-300 bg-white">
                        </label>
                    </div>
                </fieldset>

                <div id="broadcastProgress" hidden>
                    <div class="app-meter" role="progressbar" aria-label="Delivery progress"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="broadcastMeter">
                        <div id="broadcastMeterFill" class="app-meter-fill"></div>
                    </div>
                    <p id="broadcastStatus" class="mt-2 text-xs font-semibold text-slate-500" role="status" aria-live="polite">
                        Starting&hellip;
                    </p>
                </div>

                <div class="app-modal-actions">
                    <button type="submit" id="broadcastSend" class="btn-primary justify-center">
                        Send Announcement
                    </button>
                </div>
            </form>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Phone delivery</p>
            @if($smsReady)
                <p class="text-sm leading-6 text-slate-600">
                    The <span class="font-bold">{{ $smsGateway }}</span> gateway is connected, so ticking
                    SMS above sends real texts to the {{ $usersWithPhone }} accounts that have a number.
                    The list below is still available if you want to run a campaign elsewhere as well.
                </p>
            @else
                <p class="text-sm leading-6 text-slate-600">
                    No SMS gateway is connected to this application, so a bulk text cannot be sent from here yet.
                    Download the recipient list &mdash; names, stored numbers and the same number normalised to
                    E.164 &mdash; and upload it to any SMS or WhatsApp Business panel you already pay for.
                    Add an endpoint, sender ID and API key under Admin Settings &rarr; SMS Gateway to send from this page.
                </p>
            @endif
            <div class="mt-4 app-modal-actions">
                <a href="{{ route('admin.broadcast.export') }}" class="btn-outline justify-center">
                    Download Recipient List
                </a>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Recently sent</p>

            <div class="space-y-3">
                @forelse($recentBroadcasts as $broadcast)
                    <article class="app-note-card">
                        <div class="text-sm font-bold">{{ $broadcast['title'] }}</div>
                        <div class="mt-1 text-sm opacity-80">{{ $broadcast['message'] }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs opacity-70">
                            <span class="app-flag app-flag-unread">{{ number_format($broadcast['recipients']) }} accounts</span>
                            <span>{{ optional($broadcast['sent_at'])->format('d M Y, h:ia') }}</span>
                        </div>
                    </article>
                @empty
                    <div class="app-note-card text-sm opacity-70">No announcements have been sent yet.</div>
                @endforelse
            </div>
        </section>
    </div>

    <x-confirm-modal id="confirmBroadcast" title="Confirm Announcement" confirmText="Send Now" />

    <script>
        (function () {
            const form = document.getElementById('broadcastForm');
            if (!form) return;

            const sendBtn = document.getElementById('broadcastSend');
            const progress = document.getElementById('broadcastProgress');
            const meter = document.getElementById('broadcastMeter');
            const fill = document.getElementById('broadcastMeterFill');
            const status = document.getElementById('broadcastStatus');
            const counter = document.getElementById('broadcastCount');
            const messageBox = document.getElementById('broadcast_message');
            const total = Math.max(0, Number(form.dataset.total || 0));
            const endpoint = form.getAttribute('action');
            const token = form.querySelector('input[name="_token"]').value;

            messageBox.addEventListener('input', () => {
                counter.textContent = String(messageBox.value.length);
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                // The sheet raises this form again with the flag set; that pass is the release.
                if (form.dataset.sheetConfirmed === '1') {
                    delete form.dataset.sheetConfirmed;
                    dispatch();
                    return;
                }

                if (!form.reportValidity()) return;

                const channels = chosenChannels();
                if (channels.length === 0) {
                    showAppDialog({ tone: 'info', title: 'Choose a channel', message: 'Pick at least one delivery channel before sending this announcement.' });
                    return;
                }

                openConfirmModal('confirmBroadcast', {
                    Headline: document.getElementById('broadcast_title').value.trim(),
                    Recipients: total.toLocaleString()+' accounts',
                    Channels: channels.map(labelForChannel).join(', '),
                }, form.id);

                // Set after opening: cancelling runs hideOverlay, which clears exactly this key.
                form.dataset.sheetConfirmed = '1';
            });

            function chosenChannels() {
                return Array.from(form.querySelectorAll('input[name="channels[]"]:checked')).map(input => input.value);
            }

            function labelForChannel(channel) {
                if (channel === 'mail') return 'Email';
                if (channel === 'sms') return 'SMS';
                return 'In-app inbox';
            }

            function paint(done) {
                const pct = total > 0 ? Math.round((done / total) * 100) : 100;
                fill.style.width = pct + '%';
                meter.setAttribute('aria-valuenow', String(pct));
            }

            async function dispatch() {
                if (typeof window.hideGlobalLoader === 'function') window.hideGlobalLoader();
                if (sendBtn.disabled) return;

                sendBtn.disabled = true;
                sendBtn.classList.add('btn-loading');
                progress.hidden = false;
                paint(0);

                const payload = {
                    title: document.getElementById('broadcast_title').value.trim(),
                    message: messageBox.value.trim(),
                    _token: token,
                };

                let after = 0, delivered = 0, emailed = 0, texted = 0, textFailed = 0, batches = 0, gatewayNote = '';

                try {
                    while (true) {
                        const body = new URLSearchParams(payload);
                        body.set('after', String(after));
                        chosenChannels().forEach(channel => body.append('channels[]', channel));

                        batches += 1;
                        status.textContent = 'Delivering batch ' + batches + '\u2026 ' + delivered + ' of ' + total + ' accounts done.';

                        const response = await fetch(endpoint, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                            body: body,
                        });

                        const result = await response.json().catch(() => null);

                        if (!response.ok || !result || result.ok !== true) {
                            throw new Error((result && result.message) || 'The server stopped the delivery.');
                        }

                        delivered += Number(result.sent || 0);
                        emailed += Number(result.emails || 0);
                        texted += Number(result.sms_sent || 0);
                        textFailed += Number(result.sms_failed || 0);
                        if (result.gateway_message) gatewayNote = result.gateway_message;
                        after = Number(result.after || after);
                        paint(total - Number(result.remaining || 0));

                        if (!result.has_more) break;
                    }

                    let summary = 'Finished: ' + delivered + ' accounts notified';
                    if (emailed > 0) summary += ', ' + emailed + ' emails queued';
                    if (texted > 0 || textFailed > 0) {
                        summary += ', ' + texted + ' texts sent';
                        if (textFailed > 0) summary += ' (' + textFailed + ' failed)';
                    }
                    status.textContent = summary + '.';

                    // Nothing dismisses this on a timer. A delivery that took
                    // minutes to run is not worth a message that disappears before
                    // the admin has read how many went out.
                    const troubled = textFailed > 0 && gatewayNote;
                    showAppDialog({
                        tone: troubled ? 'error' : 'success',
                        title: troubled ? 'Delivered, with a problem' : 'Announcement delivered',
                        message: troubled ? summary + '. Some texts were rejected: ' + gatewayNote : summary + '.',
                        actions: [
                            { label: 'Fresh form', variant: 'primary', href: window.location.href },
                            { label: 'Stay here', variant: 'muted' },
                        ],
                    });
                } catch (error) {
                    status.textContent = error.message;
                    showAppDialog({ tone: 'error', title: 'Delivery stopped', message: error.message });
                    sendBtn.disabled = false;
                    sendBtn.classList.remove('btn-loading');
                }
            }
        })();
    </script>
</x-app-layout>
