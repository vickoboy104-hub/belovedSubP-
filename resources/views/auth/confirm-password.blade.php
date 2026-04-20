<x-guest-layout>
    <div class="space-y-6">

        <h2 class="text-2xl font-extrabold text-white">Confirm Password</h2>
        <p class="text-white/60 text-sm">
            For security reasons, please confirm your password before continuing.
        </p>

        <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" class="block mt-1 w-full"
                              type="password" name="password"
                              required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-red-400" />
            </div>

            <div class="flex items-center justify-end">
                <x-primary-button>
                    {{ __('Confirm') }}
                </x-primary-button>
            </div>
        </form>

    </div>
</x-guest-layout>
