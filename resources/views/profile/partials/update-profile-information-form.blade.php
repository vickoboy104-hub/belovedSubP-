<section>
    @php
        $fallbackFirst = trim((string) ($user->first_name ?? ''));
        $fallbackLast = trim((string) ($user->last_name ?? ''));

        if ($fallbackFirst === '' && $fallbackLast === '' && !empty($user->name)) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];
            $fallbackFirst = $parts[0] ?? '';
            $fallbackLast = $parts[1] ?? '';
        }
    @endphp

    <form method="post" action="{{ route('profile.update') }}" class="space-y-5">
        @csrf
        @method('patch')

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="first_name" :value="__('First Name')" />
                <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full"
                              :value="old('first_name', $fallbackFirst)" required autofocus autocomplete="given-name" />
                <x-input-error class="mt-2 text-red-400" :messages="$errors->get('first_name')" />
            </div>

            <div>
                <x-input-label for="last_name" :value="__('Last Name')" />
                <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full"
                              :value="old('last_name', $fallbackLast)" required autocomplete="family-name" />
                <x-input-error class="mt-2 text-red-400" :messages="$errors->get('last_name')" />
            </div>
        </div>

        <div>
            <x-input-label for="phone" :value="__('Phone Number')" />
            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                          :value="old('phone', $user->phone)" placeholder="+2348012345678 or 08012345678"
                          autocomplete="tel" />
            <x-input-error class="mt-2 text-red-400" :messages="$errors->get('phone')" />
            <p class="mt-2 text-xs text-slate-500">This number is used for virtual account assignment and account recovery verification.</p>
        </div>

        <div>
            <x-input-label for="email" :value="__('Email Address')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                          :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2 text-red-400" :messages="$errors->get('email')" />
        </div>

        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm text-amber-700">
                    Your email address is not verified.
                </p>

                <button form="send-verification" class="mt-3 btn-soft" type="submit">
                    Resend Verification Email
                </button>

                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 text-sm text-emerald-700">
                        Verification link sent successfully.
                    </p>
                @endif
            </div>
        @endif

        <div class="flex items-center gap-3">
            <x-primary-button>{{ __('Save Changes') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p class="text-sm text-emerald-700">Saved</p>
            @endif
        </div>
    </form>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>
</section>
