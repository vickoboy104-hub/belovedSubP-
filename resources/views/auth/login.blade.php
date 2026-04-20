<x-guest-layout>
    <div class="space-y-8">
        <div>
            <h1 class="text-4xl font-extrabold tracking-tight text-slate-900">Account Login</h1>
            <p class="mt-3 text-lg leading-8 text-slate-500">Welcome back. Enter your details to continue.</p>
        </div>

        <x-auth-session-status class="rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700" :status="session('status')" />

        <form method="POST" action="{{ route('login', absolute: false) }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="email" value="Username or Email" />
                <x-text-input id="email"
                              class="block mt-1 w-full"
                              type="email"
                              name="email"
                              :value="old('email')"
                              required
                              autofocus
                              autocomplete="username"
                              placeholder="Your username or email" />
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div>
                <x-input-label for="password" value="Password" />
                <x-text-input id="password"
                              class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required
                              autocomplete="current-password"
                              placeholder="Your password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div class="flex items-center justify-between gap-3">
                <label for="remember_me" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input id="remember_me"
                           type="checkbox"
                           class="rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                           name="remember">
                    <span>Keep me logged in.</span>
                </label>

                @if(Route::has('password.request'))
                    <a class="text-sm font-semibold text-slate-700 hover:text-slate-900"
                       href="{{ route('password.request', absolute: false) }}">
                        Forgot Password?
                    </a>
                @endif
            </div>

            <x-primary-button class="w-full justify-center py-4 text-base">
                Log in
            </x-primary-button>
        </form>

        <p class="text-center text-base text-slate-600">
            Don't have an account yet?
            <a href="{{ route('register', absolute: false) }}" class="font-bold text-slate-900 hover:underline">
                Sign Up Now
            </a>
        </p>
    </div>
</x-guest-layout>
