<x-guest-layout>
    <div class="space-y-7">
        <div class="text-center lg:text-left">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Welcome back</h1>
            <p class="mt-3 text-base leading-7 text-slate-500">Sign in to access your identity services and wallet.</p>
        </div>

        {{-- A notice the visitor can put away for good. It stays out of the way of
             the form below it, and the dismissal is remembered so the same line
             does not greet them on every failed password attempt. --}}
        <div x-data="{ dismissed: localStorage.getItem('loginNoticeDismissed') === '1' }"
             x-show="!dismissed"
             x-cloak
             class="reference-auth-notice">
            <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 flex-none" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 8h.01M11 12h1v4h1"></path>
            </svg>
            <span>
                <strong>Need a hand first?</strong>
                Tap the WhatsApp button at the bottom of the screen and our support team will reply right away.
            </span>
            <button type="button"
                    class="reference-auth-notice-close"
                    aria-label="Dismiss this notice"
                    @click="dismissed = true; localStorage.setItem('loginNoticeDismissed', '1')">&times;</button>
        </div>

        <x-auth-session-status class="rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700" :status="session('status')" />

        <form method="POST" action="{{ route('login', absolute: false) }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="email" value="Email address" />
                <div class="app-field mt-1">
                    <span class="app-field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                            <path d="m3.5 7 8.5 6 8.5-6"></path>
                        </svg>
                    </span>
                    <x-text-input id="email"
                                  class="block w-full"
                                  type="email"
                                  name="email"
                                  :value="old('email')"
                                  required
                                  autofocus
                                  autocomplete="username"
                                  placeholder="Your email address" />
                </div>
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div x-data="{ revealed: false }">
                <x-input-label for="password" value="Password" />
                <div class="app-field mt-1">
                    <span class="app-field-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="4" y="10" width="16" height="10" rx="2"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>
                    </span>
                    {{-- x-bind rather than :type, because a leading colon would make
                         Blade look for a PHP variable that only exists in Alpine. --}}
                    <x-text-input id="password"
                                  class="block w-full"
                                  x-bind:type="revealed ? 'text' : 'password'"
                                  name="password"
                                  data-revealable
                                  required
                                  autocomplete="current-password"
                                  placeholder="Your password" />
                    <button type="button"
                            class="app-field-reveal"
                            :aria-label="revealed ? 'Hide password' : 'Show password'"
                            @click="revealed = !revealed">
                        <svg x-show="!revealed" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg x-show="revealed" x-cloak viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 3l18 18"></path>
                            <path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-2.4 3.1"></path>
                            <path d="M6.3 7.9A16.8 16.8 0 0 0 2.5 12S6 18 12 18a9.4 9.4 0 0 0 3.4-.6"></path>
                        </svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div class="flex items-center justify-between gap-3">
                <label for="remember_me" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input id="remember_me"
                           type="checkbox"
                           class="rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                           name="remember">
                    <span>Remember me</span>
                </label>

                @if(Route::has('password.request'))
                    <a class="text-sm font-semibold text-slate-700 hover:text-slate-900"
                       href="{{ route('password.request', absolute: false) }}">
                        Forgot password?
                    </a>
                @endif
            </div>

            <x-primary-button class="w-full justify-center py-4 text-base">
                Sign in
            </x-primary-button>
        </form>

        <p class="text-center text-base text-slate-600">
            Don't have an account yet?
            <a href="{{ route('register', absolute: false) }}" class="font-bold text-slate-900 hover:underline">
                Create an account
            </a>
        </p>
    </div>
</x-guest-layout>
