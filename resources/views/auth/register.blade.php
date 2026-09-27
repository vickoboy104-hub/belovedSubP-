<x-guest-layout>
    <div class="space-y-8">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Create an account</h1>
            <p class="mt-3 text-base leading-7 text-slate-600">Get started with identity services, your wallet and everyday payments.</p>
        </div>

        <form method="POST" action="{{ route('register', absolute: false) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="ref" value="{{ old('ref', $referralCode ?? '') }}">

            @if(!empty(old('ref', $referralCode ?? '')))
                <div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    Referral code applied: <span class="font-bold">{{ old('ref', $referralCode ?? '') }}</span>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="first_name" value="First Name" />
                    <x-text-input id="first_name"
                                  class="block mt-1 w-full"
                                  type="text"
                                  name="first_name"
                                  :value="old('first_name')"
                                  required
                                  autofocus
                                  autocomplete="given-name"
                                  placeholder="First name" />
                    <x-input-error :messages="$errors->get('first_name')" class="mt-2 text-sm text-rose-600" />
                </div>

                <div>
                    <x-input-label for="last_name" value="Last Name" />
                    <x-text-input id="last_name"
                                  class="block mt-1 w-full"
                                  type="text"
                                  name="last_name"
                                  :value="old('last_name')"
                                  required
                                  autocomplete="family-name"
                                  placeholder="Last name" />
                    <x-input-error :messages="$errors->get('last_name')" class="mt-2 text-sm text-rose-600" />
                </div>
            </div>

            <div>
                <x-input-label for="email" value="Email Address" />
                <x-text-input id="email"
                              class="block mt-1 w-full"
                              type="email"
                              name="email"
                              :value="old('email')"
                              required
                              autocomplete="username"
                              placeholder="you@example.com" />
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div>
                <x-input-label for="password" value="Password" />
                <x-text-input id="password"
                              class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required
                              autocomplete="new-password"
                              placeholder="Create password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-sm text-rose-600" />
            </div>

            <div>
                <x-input-label for="password_confirmation" value="Confirm Password" />
                <x-text-input id="password_confirmation"
                              class="block mt-1 w-full"
                              type="password"
                              name="password_confirmation"
                              required
                              autocomplete="new-password"
                              placeholder="Confirm password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2 text-sm text-rose-600" />
            </div>

            <x-primary-button class="w-full justify-center py-4 text-base">
                Create Account
            </x-primary-button>
        </form>

        <p class="text-center text-base text-slate-600">
            Already have an account?
            <a href="{{ route('login', absolute: false) }}" class="font-bold text-slate-900 hover:underline">
                Sign in
            </a>
        </p>
    </div>
</x-guest-layout>
