<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-8">
        <section>
            <h1 class="app-page-title">My Profile</h1>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="app-section p-6 sm:p-8">
                <div class="text-2xl font-extrabold text-slate-900">Profile Information</div>
                <p class="mt-2 text-sm leading-6 text-slate-500">Update your identity and contact details for wallet funding and account recovery.</p>
                <div class="mt-6">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </section>

            <section class="app-section p-6 sm:p-8">
                <div class="text-2xl font-extrabold text-slate-900">Change Password</div>
                <p class="mt-2 text-sm leading-6 text-slate-500">Use a strong password to keep your account safe.</p>
                <div class="mt-6">
                    @include('profile.partials.update-password-form')
                </div>
            </section>
        </div>

        <section class="rounded-[26px] border border-rose-200 bg-white p-6 shadow-[0_16px_40px_rgba(18,31,56,0.05)] sm:p-8">
            <div class="text-2xl font-extrabold text-rose-700">Delete Account</div>
            <p class="mt-2 text-sm leading-6 text-slate-500">This action is permanent and cannot be undone.</p>
            <div class="mt-6">
                @include('profile.partials.delete-user-form')
            </div>
        </section>
    </div>
</x-app-layout>
