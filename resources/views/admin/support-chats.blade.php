<x-app-layout>
    <x-slot name="header">
        Support Chats
    </x-slot>

    <div class="h-[calc(100vh-13rem)] min-h-[540px] grid grid-cols-1 lg:grid-cols-12 gap-4">
        <div class="lg:col-span-4 rounded-3xl border border-white/10 bg-white/5 p-4 overflow-hidden flex flex-col">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h3 class="font-extrabold text-lg">Sessions</h3>
                <button id="refreshSupportTickets" class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/15 text-xs font-bold">Refresh</button>
            </div>
            <input id="supportTicketSearch"
                   type="text"
                   placeholder="Search by subject or user..."
                   class="w-full mb-3 px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-sm text-white">
            <div id="supportTicketsList" class="flex-1 overflow-y-auto space-y-2">
                @foreach($tickets as $ticket)
                    <button type="button"
                            class="support-ticket-item w-full text-left rounded-2xl border border-white/10 bg-black/20 p-3 hover:bg-white/10 transition"
                            data-ticket-id="{{ $ticket->id }}"
                            data-search="{{ strtolower(($ticket->subject ?? '') . ' ' . ($ticket->user?->name ?? '') . ' ' . ($ticket->user?->email ?? '')) }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-bold text-sm truncate">#{{ $ticket->id }} {{ $ticket->subject ?: 'No subject' }}</div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-white/10 uppercase">{{ $ticket->status }}</span>
                        </div>
                        <div class="text-xs text-white/60 mt-1 truncate">{{ $ticket->user?->name ?? 'User' }} - {{ $ticket->user?->email ?? '-' }}</div>
                        <div class="text-xs text-white/50 mt-1 truncate">{{ $ticket->latestMessage?->message ?? $ticket->message }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="lg:col-span-8 rounded-3xl border border-white/10 bg-white/5 p-4 flex flex-col min-h-0">
            <div id="supportChatHeader" class="border-b border-white/10 pb-3 mb-3">
                <div class="text-sm text-white/60">Select a session to start chatting.</div>
            </div>

            <div id="supportMessagesBox" class="flex-1 min-h-0 overflow-y-auto space-y-3 pr-1">
                <div class="text-sm text-white/50">No session selected.</div>
            </div>

            <form id="supportReplyForm" class="mt-3 border-t border-white/10 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-2">
                    <select id="supportTicketStatus" class="px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-sm">
                        <option value="">Keep status</option>
                        <option value="open">Open</option>
                        <option value="pending">Pending</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                    <input id="supportReplyMessage"
                           type="text"
                           placeholder="Type message..."
                           class="sm:col-span-3 px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-sm text-white">
                </div>
                <div class="flex items-center justify-end">
                    <button type="submit"
                            id="supportReplySendBtn"
                            class="px-4 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 text-white text-sm font-bold">
                        Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const sessionsUrl = @json(route('support.chat.sessions'));
            const messagesUrlTemplate = @json(route('support.chat.messages', ['ticket' => '__ID__']));
            const sendUrlTemplate = @json(route('support.chat.messages.send', ['ticket' => '__ID__']));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const listEl = document.getElementById('supportTicketsList');
            const searchEl = document.getElementById('supportTicketSearch');
            const refreshBtn = document.getElementById('refreshSupportTickets');
            const headerEl = document.getElementById('supportChatHeader');
            const messagesEl = document.getElementById('supportMessagesBox');
            const form = document.getElementById('supportReplyForm');
            const messageInput = document.getElementById('supportReplyMessage');
            const statusInput = document.getElementById('supportTicketStatus');
            const sendBtn = document.getElementById('supportReplySendBtn');

            let activeTicketId = null;
            let pollTimer = null;
            let sessionsCache = [];

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
            }

            function routeForTicket(template, ticketId) {
                return template.replace('__ID__', encodeURIComponent(String(ticketId)));
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatDate(iso) {
                if (!iso) return '';
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return '';
                return d.toLocaleString();
            }

            function renderSessions() {
                const q = String(searchEl.value || '').trim().toLowerCase();
                const rows = sessionsCache.filter((t) => {
                    if (!q) return true;
                    const hay = [t.subject, t.category, t.user?.name, t.user?.email, t.last_message].join(' ').toLowerCase();
                    return hay.includes(q);
                });

                if (rows.length === 0) {
                    listEl.innerHTML = '<div class="text-sm text-white/60">No sessions found.</div>';
                    return;
                }

                listEl.innerHTML = rows.map((t) => {
                    const active = Number(activeTicketId) === Number(t.id);
                    return `
                        <button type="button"
                                class="support-ticket-item w-full text-left rounded-2xl border ${active ? 'border-orange-500/40 bg-orange-500/10' : 'border-white/10 bg-black/20'} p-3 hover:bg-white/10 transition"
                                data-ticket-id="${t.id}">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-bold text-sm truncate">#${t.id} ${escapeHtml(t.subject || 'No subject')}</div>
                                <div class="flex items-center gap-1">
                                    ${t.unread_count > 0 ? `<span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500 text-white font-bold">${t.unread_count}</span>` : ''}
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-white/10 uppercase">${escapeHtml(t.status || 'open')}</span>
                                </div>
                            </div>
                            <div class="text-xs text-white/60 mt-1 truncate">${escapeHtml(t.user?.name || 'User')} - ${escapeHtml(t.user?.email || '-')}</div>
                            <div class="text-xs text-white/50 mt-1 truncate">${escapeHtml(t.last_message || '')}</div>
                        </button>
                    `;
                }).join('');

                listEl.querySelectorAll('[data-ticket-id]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-ticket-id');
                        if (!id) return;
                        selectTicket(id);
                    });
                });
            }

            async function fetchSessions() {
                const res = await fetch(sessionsUrl, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                sessionsCache = Array.isArray(data?.tickets) ? data.tickets : [];
                renderSessions();

                if (!activeTicketId && sessionsCache.length > 0) {
                    selectTicket(sessionsCache[0].id);
                }
            }

            async function selectTicket(ticketId) {
                activeTicketId = ticketId;
                renderSessions();
                await loadMessages();
                startPolling();
            }

            async function loadMessages() {
                if (!activeTicketId) return;
                const url = routeForTicket(messagesUrlTemplate, activeTicketId);
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                const msgs = Array.isArray(data?.messages) ? data.messages : [];
                const t = data?.ticket || {};

                headerEl.innerHTML = `
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="font-bold text-base">Ticket #${escapeHtml(t.id || activeTicketId)} - ${escapeHtml(t.subject || 'No subject')}</div>
                            <div class="text-xs text-white/60 mt-1">Category: ${escapeHtml(t.category || '-')} | Status: ${escapeHtml((t.status || '').toUpperCase())}</div>
                        </div>
                    </div>
                `;

                if (msgs.length === 0) {
                    messagesEl.innerHTML = '<div class="text-sm text-white/60">No messages yet.</div>';
                    return;
                }

                messagesEl.innerHTML = msgs.map((m) => `
                    <div class="flex ${m.is_admin ? 'justify-end' : 'justify-start'}">
                        <div class="max-w-[85%] rounded-2xl p-3 border ${m.is_admin ? 'bg-orange-600/15 border-orange-500/30' : 'bg-black/25 border-white/10'}">
                            <div class="text-[11px] text-white/60 mb-1">${escapeHtml(m.user_name || (m.is_admin ? 'Admin' : 'User'))} - ${escapeHtml(formatDate(m.created_at))}</div>
                            <div class="text-sm text-white/90 whitespace-pre-wrap break-words">${escapeHtml(m.message || '')}</div>
                            ${m.attachment_url ? `<a href="${escapeHtml(m.attachment_url)}" target="_blank" class="text-xs text-orange-300 underline mt-2 inline-block">Attachment</a>` : ''}
                        </div>
                    </div>
                `).join('');
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            async function sendReply(e) {
                e.preventDefault();
                if (!activeTicketId) {
                    notify('error', 'Select a support session first.');
                    return;
                }

                const message = String(messageInput.value || '').trim();
                if (!message) {
                    notify('error', 'Type a message first.');
                    return;
                }

                sendBtn.disabled = true;
                try {
                    const url = routeForTicket(sendUrlTemplate, activeTicketId);
                    const fd = new FormData();
                    fd.append('message', message);
                    if (statusInput.value) fd.append('status', statusInput.value);

                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: fd,
                    });
                    const data = await res.json();
                    if (!res.ok || !data?.ok) {
                        notify('error', data?.message || 'Unable to send message.');
                        return;
                    }

                    messageInput.value = '';
                    await loadMessages();
                    await fetchSessions();
                } catch (err) {
                    notify('error', 'Network error. Please try again.');
                } finally {
                    sendBtn.disabled = false;
                }
            }

            function startPolling() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(async () => {
                    if (!activeTicketId) return;
                    await loadMessages();
                    await fetchSessions();
                }, 10000);
            }

            searchEl.addEventListener('input', renderSessions);
            refreshBtn.addEventListener('click', fetchSessions);
            form.addEventListener('submit', sendReply);

            fetchSessions().catch(() => notify('error', 'Unable to load support sessions.'));
        })();
    </script>
</x-app-layout>
