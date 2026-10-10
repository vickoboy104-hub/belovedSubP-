<x-guest-layout>
    <div class="space-y-6">
        <h1 class="text-3xl font-extrabold text-slate-900">Forgot your password?</h1>
        <p class="text-slate-600 text-sm leading-6">
            Enter your email address and we'll send you a password reset link.
        </p>

        <x-auth-session-status class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" :status="session('status')" />

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
                              autofocus
                              autocomplete="email"
                              placeholder="you@example.com" />
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-rose-600" />
            </div>

            <div>
                <x-primary-button class="w-full justify-center py-3">
                    {{ __('Send Reset Link') }}
                </x-primary-button>
            </div>
        </form>

        <p class="text-center text-sm text-slate-600">
            Remembered your password?
            <a href="{{ route('login', absolute: false) }}" class="font-semibold text-blue-900 hover:underline">
                Login
            </a>
        </p>
    </div>
</x-guest-layout>
