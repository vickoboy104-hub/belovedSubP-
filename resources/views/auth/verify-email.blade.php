<x-guest-layout>
    <div class="space-y-6">

        <h2 class="text-2xl font-extrabold text-white">Verify Your Email âœ…</h2>

        <p class="text-white/60 text-sm">
            Thanks for signing up! Please verify your email address by clicking the link we sent.
            If you didnâ€™t receive the email, we can send another one.
        </p>

        @if (session('status') == 'verification-link-sent')
            <div class="p-3 rounded-xl bg-white/5 border border-white/10 text-orange-300 text-sm">
                A new verification link has been sent to your email address.
            </div>
        @endif

        <div class="flex items-center justify-between gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-primary-button>
                    {{ __('Resend Email') }}
                </x-primary-button>
            </form>

            <form method="POST" action="{{ route('logout', absolute: false) }}">
                @csrf
                <button class="btn-soft" type="submit">
                    Logout
                </button>
            </form>
        </div>

    </div>
</x-guest-layout>

