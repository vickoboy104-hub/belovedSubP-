<x-app-layout>
    @php
        $fullName = trim((string) ($user->name ?: (($user->first_name ?? '').' '.($user->last_name ?? ''))));
        $memberSince = $user->created_at?->translatedFormat('F Y');

        // The account mirror a customer reads: only what their own record says,
        // in the same words the edit form asks for, so the two pages can never
        // disagree about what a field is called.
        $fields = [
            ['label' => 'Full Name', 'value' => $fullName !== '' ? $fullName : 'Not set yet'],
            ['label' => 'Email Address', 'value' => (string) $user->email],
            ['label' => 'Phone Number', 'value' => trim((string) $user->phone) !== '' ? $user->phone : 'Not provided'],
            ['label' => 'Account Opened', 'value' => $memberSince ?? 'Recently'],
        ];
    @endphp

    <x-page-hero class="reference-shared-banner" title="My Profile" subtitle="What your account records about you right now." />

    <div class="reference-flow-page mx-auto max-w-3xl space-y-6">
        @if (session('status') === 'profile-updated')
            <div class="service-form-alert service-form-alert-warning" role="status">
                <strong>Profile saved.</strong>
                <p>The details below are the new ones. <a href="{{ route('settings.edit') }}#profile">Return to Settings</a> to change anything else.</p>
            </div>
        @endif

        <section class="app-section p-6 sm:p-8" aria-labelledby="profile-record-title">
            <div class="flex flex-wrap items-center gap-4">
                <x-avatar :user="$user" />
                <div class="min-w-0 flex-1">
                    <h2 id="profile-record-title" class="truncate text-xl font-extrabold text-slate-900">{{ $fullName !== '' ? $fullName : 'Your profile' }}</h2>
                    <p class="mt-1 truncate text-sm text-slate-500">{{ $user->email }}</p>
                </div>
                <a href="{{ route('settings.edit') }}#profile" class="btn-soft shrink-0">Edit profile</a>
            </div>

            <div class="mt-7 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($fields as $field)
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $field['label'] }}</span>
                        <input type="text" readonly value="{{ $field['value'] }}"
                               class="input-field mt-1 block w-full bg-slate-50 text-slate-700"
                               aria-label="{{ $field['label'] }} (read only)">
                    </div>
                @endforeach
            </div>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="service-form-alert service-form-alert-warning mt-6">
                    <strong>Email not verified.</strong>
                    <p>Open <a href="{{ route('settings.edit') }}#profile">Settings</a> to send the verification link again.</p>
                </div>
            @endif

            <p class="mt-6 text-sm leading-6 text-slate-500">
                This page is a read-only copy of your account and never changes anything.
                Names, phone number, email and profile photo are edited in
                <a href="{{ route('settings.edit') }}#profile" class="font-bold underline">Settings &rarr; Edit Profile</a>,
                and your password in <a href="{{ route('settings.edit') }}#security-settings" class="font-bold underline">Settings &rarr; Change Password</a>.
            </p>
        </section>
    </div>
</x-app-layout>
