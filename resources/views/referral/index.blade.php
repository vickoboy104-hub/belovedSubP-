<x-app-layout>
    @php
        $naira = fn (int $kobo) => number_format($kobo / 100, 2);
        $maskPhone = function (string $phone): string {
            $phone = preg_replace('/\D+/', '', $phone);
            if (strlen($phone) < 7) {
                return $phone !== '' ? 'Hidden' : 'No phone';
            }
            return substr($phone, 0, 4) . '•••' . substr($phone, -4);
        };
    @endphp

    <div class="reference-dashboard">
        <x-page-hero title="Invite & Earn" subtitle="Share your link. When someone buys with it, you earn a percentage of their spend." />

        <div class="reference-dashboard-body space-y-5">
            @unless($systemEnabled)
                <div class="rounded-2xl border border-amber-300/40 bg-amber-100 p-4 text-sm font-semibold text-amber-900">
                    Referral rewards are currently paused by the administrator. Your link still works, but commissions are not being credited right now.
                </div>
            @endunless

            <section class="reference-summary-grid" aria-label="Referral balance">
                <div class="reference-summary-card reference-summary-blue">
                    <div class="reference-card-caption">Available (₦)</div>
                    <strong class="reference-money">₦{{ $naira($balanceKobo) }}</strong>
                    <a href="#withdraw" class="reference-full-button reference-light-button">Withdraw to wallet</a>
                </div>
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Total earned (₦)</div>
                    <strong class="reference-money">₦{{ $naira($totalKobo) }}</strong>
                    <span class="reference-account-bank">{{ $qualifiedCount }} of {{ $invitedCount }} people earned you</span>
                </div>
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Moved to wallet (₦)</div>
                    <strong class="reference-money">₦{{ $naira($withdrawnKobo) }}</strong>
                    <span class="reference-account-bank">{{ $invitedCount }} invited</span>
                </div>
            </section>

            <section class="rounded-2xl border border-black/10 bg-white p-5 shadow-[0_12px_34px_rgba(20,40,80,0.08)]">
                <h2 class="reference-section-title" id="your-link">Your referral link</h2>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center"
                     x-data="{
                        link: @js($referralLink),
                        copied: false,
                        async copyLink() {
                            try {
                                await navigator.clipboard.writeText(this.link);
                            } catch (error) {
                                const field = this.$refs.input;
                                field.focus();
                                field.select();
                                document.execCommand('copy');
                            }
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2200);
                        }
                     }">
                    <input x-ref="input"
                           readonly
                           :value="link"
                           class="w-full min-w-0 rounded-xl border border-black/10 bg-slate-50 px-3 py-3 text-sm text-slate-800"
                           aria-label="Your referral link" />
                    <button type="button"
                            @click="copyLink()"
                            class="btn-primary shrink-0 justify-center"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <a href="{{ $whatsAppShareLink }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center justify-center rounded-xl bg-[#21bf5b] px-4 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#1aa54c]">
                        Share on WhatsApp
                    </a>
                    <a href="/r/{{ $referralCode }}" class="reference-quiet-button">
                        Open my link
                    </a>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    Your code is <span class="font-extrabold tracking-wider text-slate-800">{{ $referralCode }}</span> —
                    people can also type it when signing up.
                </p>
            </section>

            @if($serviceRates !== [])
                <section class="rounded-2xl border border-black/10 bg-white p-5 shadow-[0_12px_34px_rgba(20,40,80,0.08)]">
                    <h2 class="reference-section-title">What you earn</h2>
                    <p class="mt-1 text-xs text-slate-500">Paid as soon as the person you invited completes a purchase.</p>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach($serviceRates as $rate)
                            <div class="rounded-xl bg-slate-50 p-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $rate['label'] }}</div>
                                <div class="mt-1 text-xl font-extrabold text-[#1b3f74]">{{ rtrim(rtrim(number_format($rate['percent'], 2), '0'), '.') }}%</div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section id="withdraw" class="scroll-mt-24 rounded-2xl border border-black/10 bg-white p-5 shadow-[0_12px_34px_rgba(20,40,80,0.08)]">
                <h2 class="reference-section-title">Withdraw to wallet</h2>
                <p class="mt-1 text-xs text-slate-500">Commission moves into your wallet balance and can be spent on any service.</p>

                @if($balanceKobo > 0)
                    <form method="POST" action="{{ route('referral.withdraw') }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                        @csrf
                        <label class="sr-only" for="amount">Amount in naira</label>
                        <input id="amount" name="amount" type="number" inputmode="decimal" min="1" max="100000" step="0.01"
                               value="{{ old('amount') }}"
                               class="w-full min-w-0 rounded-xl border border-black/10 px-3 py-3 text-sm text-slate-900"
                               placeholder="Amount to withdraw" required />
                        <button type="submit" class="btn-primary shrink-0 justify-center">Withdraw</button>
                    </form>
                    <button type="button"
                            class="btn-outline mt-2 w-full justify-center"
                            x-data
                            @click="document.getElementById('amount').value = @js(round($balanceKobo / 100, 2))">
                        Withdraw all (₦{{ $naira($balanceKobo) }})
                    </button>
                    @error('amount')
                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                @else
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                        Nothing to withdraw yet. You will see a balance here after someone you invited completes their first funded purchase.
                    </p>
                @endif
            </section>

            <section class="rounded-2xl border border-black/10 bg-white p-5 shadow-[0_12px_34px_rgba(20,40,80,0.08)]">
                <h2 class="reference-section-title">People you invited</h2>
                @if($invitedUsers->count())
                    <div class="mt-3 divide-y divide-black/5">
                        @foreach($invitedUsers as $invitee)
                            <div class="flex items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-bold text-slate-900">
                                        {{ trim(($invitee->first_name ?? '') . ' ' . ($invitee->last_name ?? '')) ?: ($invitee->name ?: 'Member') }}
                                    </div>
                                    <div class="text-xs text-slate-500">{{ $maskPhone((string) $invitee->phone) }} · joined {{ $invitee->created_at?->format('d M Y') }}</div>
                                </div>
                                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $invitee->referral_qualified_at ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $invitee->referral_qualified_at ? 'Active' : 'Waiting to fund' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                        Nobody yet. Share your link above — the moment someone registers through it they appear here.
                    </p>
                @endif
            </section>

            <section class="rounded-2xl border border-black/10 bg-white p-5 shadow-[0_12px_34px_rgba(20,40,80,0.08)]">
                <h2 class="reference-section-title">Commission activity</h2>
                @if($activity->count())
                    <div class="reference-recent mt-3">
                        @foreach($activity as $entry)
                            <div class="reference-order-row flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-bold text-slate-900">{{ $entry['label'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $entry['at']?->format('d M Y, g:ia') }}</div>
                                </div>
                                <div class="shrink-0 text-sm font-extrabold {{ $entry['is_credit'] ? 'text-emerald-700' : 'text-slate-700' }}">
                                    {{ $entry['is_credit'] ? '+' : '' }}₦{{ $naira($entry['amount_kobo']) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">No commission yet.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
