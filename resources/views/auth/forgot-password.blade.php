<x-guest-layout>
    <div class="space-y-6">
        <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Forgot Password?</h2>
        <p class="text-gray-600 dark:text-white/60 text-sm">
            No worries. Enter your email and we'll send you a reset link.
        </p>

        <x-auth-session-status class="mb-4 text-gray-600 dark:text-white/70" :status="session('status')" />

        <form method="POST" action="{{ route('password.email', absolute: false) }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="email" :value="__('Email Address')" />
                <x-text-input id="email"
                              class="block mt-1 w-full"
                              type="email"
                              name="email"
                              :value="old('email')"
                              required
                              autofocus />
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-red-500 dark:text-red-400" />
            </div>

            <div class="flex items-center justify-end">
                <x-primary-button>
                    {{ __('Send Reset Link') }}
                </x-primary-button>
            </div>
        </form>

        <p class="text-sm text-gray-600 dark:text-white/60">
            Remembered your password?
            <a href="{{ route('login', absolute: false) }}" class="text-orange-500 dark:text-orange-400 font-semibold hover:text-orange-400 dark:hover:text-orange-300">
                Login
            </a>
        </p>
    </div>
</x-guest-layout>
