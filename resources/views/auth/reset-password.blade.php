<x-guest-layout>
    <div class="space-y-6">
        <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Reset Password</h2>
        <p class="text-gray-600 dark:text-white/60 text-sm">Set a new password to regain access.</p>

        <form method="POST" action="{{ route('password.store', absolute: false) }}" class="space-y-5">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <x-input-label for="email" :value="__('Email Address')" />
                <x-text-input id="email"
                              class="block mt-1 w-full"
                              type="email"
                              name="email"
                              :value="old('email', $request->email)"
                              required
                              autofocus
                              autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-red-500 dark:text-red-400" />
            </div>

            <div>
                <x-input-label for="password" :value="__('New Password')" />
                <x-text-input id="password"
                              class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required
                              autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-red-500 dark:text-red-400" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation"
                              class="block mt-1 w-full"
                              type="password"
                              name="password_confirmation"
                              required
                              autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2 text-red-500 dark:text-red-400" />
            </div>

            <div class="flex items-center justify-end">
                <x-primary-button>
                    {{ __('Reset Password') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-guest-layout>
