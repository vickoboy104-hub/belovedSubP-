<x-app-layout>
    <x-slot name="header">
        Website Editor
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        <div class="rounded-3xl p-6 border border-red-400/30 bg-red-500/10">
            <div class="text-xl font-extrabold text-red-200">Danger Zone</div>
            <p class="text-sm text-red-100/90 mt-2">
                Changes here immediately affect your live website layout, colors, and behavior.
                Only proceed if you understand the impact.
            </p>
        </div>

        @if(session('success'))
            <div class="p-4 rounded-2xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 text-green-700 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="p-4 rounded-2xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-200">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="list-disc ml-5 mt-2">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.website-editor.update') }}"
              class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-8">
            @csrf

            <section>
                <h3 class="text-lg font-extrabold">Feature Toggles</h3>
                <p class="text-sm text-white/60 mt-1">Turn sections on/off without touching code.</p>

                <div class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
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
                        <label class="flex items-center gap-2 rounded-2xl border border-white/10 bg-black/10 p-3">
                            <input type="checkbox" name="{{ $key }}" value="1" @checked($checked)
                                   class="w-5 h-5 rounded border-white/20 bg-black/30">
                            <span class="text-xs sm:text-sm font-semibold leading-tight">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Theme Colors</h3>
                <p class="text-sm text-white/60 mt-1">Global colors for buttons, links, surfaces and header.</p>

                <div class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
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
                        <div class="rounded-2xl border border-white/10 bg-black/10 p-4">
                            <label class="text-sm font-bold text-white/80">{{ $meta['label'] }}</label>
                            <div class="mt-2 flex items-center gap-3">
                                <input type="color" value="{{ $value }}"
                                       oninput="document.getElementById('{{ $key }}').value = this.value"
                                       class="w-12 h-10 rounded-lg border border-white/20 bg-transparent p-0">
                                <input id="{{ $key }}" name="{{ $key }}" value="{{ $value }}"
                                       class="flex-1 px-3 py-2 rounded-xl bg-black/20 border border-white/10 text-white">
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Custom CSS</h3>
                <p class="text-sm text-white/60 mt-1">Use this for deep style/structure overrides.</p>
                <textarea name="editor_custom_css" rows="10"
                          class="w-full mt-3 px-4 py-3 rounded-2xl bg-black/20 border border-white/10 text-white font-mono text-xs"
                          placeholder="Example: .card { border-radius: 24px !important; }">{{ old('editor_custom_css', $settings['editor_custom_css'] ?? '') }}</textarea>
            </section>

            <section>
                <h3 class="text-lg font-extrabold">Custom JavaScript</h3>
                <p class="text-sm text-white/60 mt-1">Advanced behavior changes. Use carefully.</p>
                <textarea name="editor_custom_js" rows="8"
                          class="w-full mt-3 px-4 py-3 rounded-2xl bg-black/20 border border-white/10 text-white font-mono text-xs"
                          placeholder="Example: console.log('Custom JS loaded');">{{ old('editor_custom_js', $settings['editor_custom_js'] ?? '') }}</textarea>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit"
                        class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Apply Website Changes
                </button>
            </div>
        </form>

        <form method="POST"
              action="{{ route('admin.website-editor.reset') }}"
              class="rounded-3xl p-6 border border-red-500/25 bg-red-500/10"
              onsubmit="return confirm('Reset all Website Editor changes to default?');">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-extrabold text-red-200">Reset Editor Settings</h3>
                    <p class="text-sm text-red-100/90 mt-1">This removes all customizations made from Website Editor.</p>
                </div>
                <button type="submit"
                        class="px-5 py-3 rounded-2xl bg-red-600 hover:bg-red-700 text-white font-extrabold transition">
                    Reset to Default
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
