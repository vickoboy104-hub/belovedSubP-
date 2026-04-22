<x-app-layout>
    <div class="max-w-4xl mx-auto w-full px-4 sm:px-0 space-y-5">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">Support Chat Bot</h2>
                    <p class="text-sm text-gray-600 dark:text-white/60 mt-1">
                        Choose a common issue, describe your complaint, and upload proof if available.
                    </p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-xl font-black">
                    CS
                </div>
            </div>
        </div>

        @if($errors->any())
            <div class="rounded-2xl p-4 border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-200">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="mt-2 list-disc ml-5 text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($setupError))
            <div class="rounded-2xl p-4 border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-200">
                {{ $setupError }}
            </div>
        @endif

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <form method="POST" action="{{ route('support.bot.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Issue Category</label>
                        <select name="category" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                            <option value="">Select category</option>
                            @foreach($issueOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Subject (Optional)</label>
                        <input type="text" name="subject" value="{{ old('subject') }}" maxlength="180"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                               placeholder="Short title">
                    </div>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">Complaint Details</label>
                    <textarea name="message" rows="5" required maxlength="2000"
                              class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                              placeholder="Tell us exactly what happened...">{{ old('message') }}</textarea>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">Proof Upload (Optional)</label>
                    <input type="file" name="attachment"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-white/50 mt-1">Accepted: image/pdf/doc. Max 5MB.</p>
                </div>

                <button type="submit"
                        class="w-full sm:w-auto px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Submit Complaint
                </button>
            </form>
        </div>

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h3 class="text-lg font-extrabold">Recent Complaints</h3>
            <div class="mt-3 space-y-2">
                @forelse($tickets as $ticket)
                    <div class="rounded-2xl p-3 border border-gray-200 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="font-semibold capitalize">{{ str_replace('_', ' ', $ticket->category) }}</div>
                            <div class="text-xs text-gray-500 dark:text-white/50">{{ optional($ticket->created_at)->format('d M Y, h:ia') }}</div>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-white/60 mt-1">{{ $ticket->subject ?: 'No subject' }}</div>
                        <div class="text-sm mt-1">{{ $ticket->message }}</div>
                        <div class="text-xs mt-2">Status: <span class="font-bold uppercase">{{ $ticket->status }}</span></div>
                    </div>
                @empty
                    <div class="text-sm text-gray-500 dark:text-white/50">No complaints submitted yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
