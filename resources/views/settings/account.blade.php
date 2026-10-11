<x-app-layout>
    @php
        // Two ways a customer arrives here with a purpose: the mobile menu and a
        // quiet default both point at the password panel, while a rejected profile
        // form has to come back to the panel whose fields are in red.
        $defaultTab = $errors->getBag('default')->isNotEmpty() ? 'profile' : 'password';
    @endphp

    <x-page-hero class="reference-shared-banner" title="Account Settings" subtitle="Change your password, or correct the details your account is built on." />

    <div class="reference-flow-page mx-auto max-w-3xl">
        <div class="app-section p-5 sm:p-8"
             x-data="{
                tab: @js($defaultTab),
                // Whichever word a link uses, it means one of these two panels.
                panels: { password: 'password', 'security-settings': 'password', profile: 'profile' },
                pick(name) {
                    this.tab = name;
                    history.replaceState(null, '', '#' + name);
                },
                readHash() {
                    const wanted = this.panels[location.hash.replace('#', '')];
                    if (wanted) this.tab = wanted;
                },
             }"
             x-init="readHash()"
             @hashchange.window="readHash()">
            <div role="tablist" aria-label="Account settings" class="app-tabs-bar">
                <button type="button" id="tab-password" role="tab"
                        class="app-tab" :class="{ 'is-active': tab === 'password' }"
                        :aria-selected="tab === 'password' ? 'true' : 'false'"
                        :tabindex="tab === 'password' ? 0 : -1"
                        @click="pick('password')">Change Password</button>
                <button type="button" id="tab-profile" role="tab"
                        class="app-tab" :class="{ 'is-active': tab === 'profile' }"
                        :aria-selected="tab === 'profile' ? 'true' : 'false'"
                        :tabindex="tab === 'profile' ? 0 : -1"
                        @click="pick('profile')">Edit Profile</button>
            </div>

            <div id="security-settings" role="tabpanel" aria-labelledby="tab-password"
                 class="app-tab-panel" x-show="tab === 'password'" x-cloak>
                <h2 class="text-lg font-extrabold text-slate-900">Change Password</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Use a strong password you do not use anywhere else. Nothing else on your account changes when you update it.</p>
                <div class="mt-6">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div id="profile" role="tabpanel" aria-labelledby="tab-profile"
                 class="app-tab-panel" x-show="tab === 'profile'" x-cloak>
                <h2 class="text-lg font-extrabold text-slate-900">Profile Information</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">These are the details written on your <a href="{{ route('profile.show') }}" class="underline font-semibold">profile page</a> and used to fund your wallet and recover your account.</p>
                <div class="mt-6">
                    @include('profile.partials.update-profile-information-form')
                </div>

                <div class="profile-danger-zone">
                    <h3 class="text-lg font-extrabold text-rose-700">Delete Account</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">This action is permanent and cannot be undone.</p>
                    <div class="mt-6">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
