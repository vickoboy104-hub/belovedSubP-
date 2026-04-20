<section x-data="{ open: false }">
    <button type="button"
        @click="open = true"
        class="rounded-xl bg-rose-600 px-5 py-2.5 font-semibold text-white transition hover:bg-rose-700">
        Delete Account
    </button>

    <div x-show="open" x-transition class="fixed inset-0 z-[999] flex items-center justify-center bg-black/70 px-4">
        <div class="w-full max-w-md rounded-[26px] border border-rose-200 bg-white p-6 shadow-[0_16px_40px_rgba(18,31,56,0.1)]">
            <h3 class="text-xl font-extrabold text-rose-700">Confirm Delete</h3>
            <p class="mt-2 text-sm text-slate-500">
                Are you sure you want to delete your account? This action cannot be undone.
            </p>

            <form method="post" action="{{ route('profile.destroy') }}" class="mt-5 space-y-4">
                @csrf
                @method('delete')

                <div>
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input id="password" name="password" type="password"
                        class="mt-1 block w-full" autocomplete="current-password" />
                    <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2 text-red-400" />
                </div>

                <div class="flex gap-3">
                    <button type="button"
                        @click="open = false"
                        class="btn-soft w-full justify-center">
                        Cancel
                    </button>

                    <button type="submit"
                        class="w-full rounded-xl bg-rose-600 px-5 py-2.5 font-semibold text-white transition hover:bg-rose-700">
                        Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
