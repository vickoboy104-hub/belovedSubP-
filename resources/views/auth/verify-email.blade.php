<x-guest-layout>
    <div class="space-y-6">

        <h1 class="text-3xl font-extrabold text-slate-900">Verify your email</h1>

        <p class="text-slate-600 text-sm leading-6">
            Thanks for signing up! Please verify your email address by clicking the link we sent.
            If you didn't receive it, you can request another link.
        </p>

        @if (session('status') == 'verification-link-sent')
            <div role="status" class="p-3 rounded-lg bg-emerald-50 border border-emerald-100 text-emerald-800 text-sm">
                A new verification link has been sent to your email address.
            </div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-primary-button class="w-full justify-center py-3 sm:w-auto">
                    {{ __('Resend Email') }}
                </x-primary-button>
            </form>

            <form method="POST" action="{{ route('logout', absolute: false) }}">
                @csrf
                <button class="btn-soft min-h-11 w-full sm:w-auto" type="submit">
                    Logout
                </button>
            </form>
        </div>

    </div>
</x-guest-layout>
