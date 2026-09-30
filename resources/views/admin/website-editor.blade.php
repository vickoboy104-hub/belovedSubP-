<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Website Editor" subtitle="Changes here affect your live layout, colours and behaviour immediately." />

    <div class="reference-flow-page admin-light-page max-w-5xl mx-auto space-y-6">
        <div class="app-section border-rose-200 bg-rose-50 p-6">
            <h2 class="text-lg font-extrabold text-rose-700">Danger Zone</h2>
            <p class="mt-2 text-sm leading-6 text-rose-800">
                Changes here immediately affect your live website layout, colors, and behavior.
                Only proceed if you understand the impact.
            </p>
        </div>

        @if($errors->any())
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="list-disc ml-5 mt-2">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="websiteEditorForm" method="POST" action="{{ route('admin.website-editor.update') }}"
              class="app-section p-6 pb-36 space-y-8">
            @csrf

            <section>
                <h3 class="text-lg font-extrabold">Feature Toggles</h3>
                <p class="text-sm text-slate-500 mt-1">Turn sections on/off without touching code.</p>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @php
                        $toggles = [
                            'editor_home_marquee_enabled' => 'Home Marquee',
                            'editor_home_popup_enabled' => 'Home Popup',
                            'editor_dashboard_marquee_enabled' => 'Dashboard Marquee',
                            'editor_dashboard_popup_enabled' => 'Dashboard Popup',
                            'editor_fund_wallet_marquee_enabled' => 'Fund Wallet Marquee',
                            'editor_whatsapp_button_enabled' => 'WhatsApp Floating Button',
                            'editor_dashboard_quick_access_enabled' => 'Dashboard Quick Access Buttons',
                            'editor_dashboard_quick_tips_enabled' => 'Dashboard Quick Tips',
                            'editor_enable_custom_css' => 'Enable Custom CSS',
                            'editor_enable_custom_js' => 'Enable Custom JavaScript',
                        ];
                    @endphp

                    @foreach($toggles as $key => $label)
                        @php
                            $default = '1';
                            $checked = old($key, $settings[$key] ?? $default) === '1';
                        @endphp
                        <label class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                            <input type="checkbox" name="{{ $key }}" value="1" @checked($checked)
                                   class="w-5 h-5 rounded border-gray-300 bg-white">
                            <span class="text-xs sm:text-sm font-semibold leading-tight">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Theme Colors</h3>
                <p class="text-sm text-slate-500 mt-1">Global colors for buttons, links, surfaces and header.</p>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @php
                        $colorFields = [
                            'editor_primary_color' => ['label' => 'Primary Color', 'default' => '#f97316'],
                            'editor_secondary_color' => ['label' => 'Secondary Color', 'default' => '#ea580c'],
                            'editor_surface_color' => ['label' => 'Surface Color', 'default' => '#0b1220'],
                            'editor_text_color' => ['label' => 'Text Color', 'default' => '#f8fafc'],
                            'editor_header_bg' => ['label' => 'Header Background', 'default' => '#0b1220'],
                            'editor_header_text_color' => ['label' => 'Header Text', 'default' => '#f8fafc'],
                        ];
                    @endphp

                    @foreach($colorFields as $key => $meta)
                        @php
                            $value = old($key, $settings[$key] ?? $meta['default']);
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <label class="text-sm font-bold text-slate-700">{{ $meta['label'] }}</label>
                            <div class="mt-2 flex items-center gap-3">
                                <input type="color" value="{{ $value }}"
                                       oninput="document.getElementById('{{ $key }}').value = this.value"
                                       class="w-12 h-10 rounded-lg border border-gray-300 bg-transparent p-0">
                                <input id="{{ $key }}" name="{{ $key }}" value="{{ $value }}"
                                       class="flex-1 px-3 py-2 rounded-xl bg-white border border-gray-300 text-gray-900">
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Custom CSS</h3>
                <p class="text-sm text-slate-500 mt-1">Use this for deep style/structure overrides.</p>
                <textarea name="editor_custom_css" rows="10"
                          class="w-full mt-3 px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 font-mono text-xs"
                          placeholder="Example: .card { border-radius: 24px !important; }">{{ old('editor_custom_css', $settings['editor_custom_css'] ?? '') }}</textarea>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Custom JavaScript</h3>
                <p class="text-sm text-slate-500 mt-1">Advanced behavior changes. Use carefully.</p>
                <textarea name="editor_custom_js" rows="8"
                          class="w-full mt-3 px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 font-mono text-xs"
                          placeholder="Example: console.log('Custom JS loaded');">{{ old('editor_custom_js', $settings['editor_custom_js'] ?? '') }}</textarea>
            </section>

        </form>

        <div class="page-save-overlay">
            <div class="page-save-overlay-card">
                <div class="page-save-overlay-copy">
                    <div class="text-sm font-extrabold text-slate-900">Website editor changes are ready</div>
                    <p class="mt-1 text-xs text-slate-500">Save from here anytime while you scroll through the admin editor.</p>
                </div>
                <button type="submit"
                        form="websiteEditorForm"
                        class="btn-primary w-full justify-center sm:w-auto">
                    Apply Website Changes
                </button>
            </div>
        </div>

        <form method="POST"
              action="{{ route('admin.website-editor.reset') }}"
              class="app-section border-rose-200 bg-rose-50 p-6"
              onsubmit="return confirm('Reset all Website Editor changes to default?');">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-extrabold text-rose-700">Reset Editor Settings</h3>
                    <p class="text-sm text-rose-800 mt-1">This removes all customizations made from Website Editor.</p>
                </div>
                <button type="submit"
                        class="px-5 py-3 bg-red-600 hover:bg-red-700 text-white font-extrabold transition">
                    Reset to Default
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
