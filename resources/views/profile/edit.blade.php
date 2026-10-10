<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="My Profile" subtitle="Update your identity and contact details for wallet funding and account recovery." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-8">
        <div class="grid gap-6 lg:grid-cols-2">
            <section id="profile-information" class="app-section p-6 sm:p-8">
                <h2 class="text-lg font-extrabold text-slate-900">Profile Information</h2>
                <div class="mt-4">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </section>

            <section id="security-settings" class="app-section p-6 sm:p-8">
                <h2 class="text-lg font-extrabold text-slate-900">Change Password</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Use a strong password to keep your account safe.</p>
                <div class="mt-6">
                    @include('profile.partials.update-password-form')
                </div>
            </section>
        </div>

        <section class="app-section border-rose-200 p-6 sm:p-8">
            <h2 class="text-lg font-extrabold text-rose-700">Delete Account</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">This action is permanent and cannot be undone.</p>
            <div class="mt-6">
                @include('profile.partials.delete-user-form')
            </div>
        </section>
    </div>
</x-app-layout>
