<section x-data="{ open: false }">
    <button type="button"
        @click="open = true"
        class="app-modal-btn app-modal-btn-danger">
        Delete Account
    </button>

    <div x-show="open" x-transition
         role="dialog" aria-modal="true" aria-labelledby="deleteAccountTitle"
         class="app-modal-overlay fixed inset-0 z-[999] flex items-center justify-center px-4">
        <div class="app-modal-panel relative overflow-hidden">
            <div class="p-6">
                <h3 id="deleteAccountTitle" class="text-xl font-extrabold text-rose-700">Confirm Delete</h3>
                <p class="mt-2 text-sm opacity-80">
                    Are you sure you want to delete your account? This action cannot be undone.
                </p>

                <form method="post" action="{{ route('profile.destroy') }}" class="mt-5 space-y-4">
                    @csrf
                    @method('delete')

                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password" name="password" type="password"
                            class="mt-1 block w-full" autocomplete="current-password" />
                        <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2 text-sm font-semibold text-rose-700" />
                    </div>

                    <div class="app-modal-actions">
                        <button type="button"
                            @click="open = false"
                            class="app-modal-btn app-modal-btn-muted">
                            Cancel
                        </button>

                        <button type="submit"
                            class="app-modal-btn app-modal-btn-danger">
                            Yes, Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
