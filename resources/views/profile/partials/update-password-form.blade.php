<section>
    <form method="post" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        @method('put')

        <div>
            <x-input-label for="current_password" :value="__('Current Password')" />
            <x-text-input id="current_password" name="current_password" type="password"
                class="mt-1 block w-full" autocomplete="current-password" />
            <x-input-error class="mt-2 text-red-400" :messages="$errors->updatePassword->get('current_password')" />
        </div>

        <div>
            <x-input-label for="password" :value="__('New Password')" />
            <x-text-input id="password" name="password" type="password"
                class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error class="mt-2 text-red-400" :messages="$errors->updatePassword->get('password')" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" name="password_confirmation" type="password"
                class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error class="mt-2 text-red-400" :messages="$errors->updatePassword->get('password_confirmation')" />
        </div>

        <div class="flex items-center gap-3">
            <x-primary-button>{{ __('Update Password') }}</x-primary-button>

            @if (session('status') === 'password-updated')
                <p class="text-sm text-emerald-700">Updated</p>
            @endif
        </div>
    </form>
</section>
