<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Admin Settings" subtitle="Control your markup, exam prices, branding, and WhatsApp support without editing code." />

    <div class="reference-flow-page admin-light-page admin-settings-page mx-auto max-w-5xl space-y-5">
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

        <form id="adminSettingsForm" method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data"
              class="rounded-3xl border border-gray-200 bg-white p-6 pb-40 space-y-6">
            @csrf

            <div class="space-y-8">
                <div class="sticky top-24 z-20 -mx-1 px-1">
                    <div class="rounded-2xl border border-gray-200 bg-slate-50 p-2 space-y-2">
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <label class="sr-only" for="settingsSearch">Search settings on this page</label>
                                <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3.2-3.2"></path>
                                </svg>
                                <input id="settingsSearch" type="search" autocomplete="off" spellcheck="false"
                                       placeholder="Search settings…"
                                       class="w-full rounded-xl border border-gray-300 bg-white py-2.5 pl-9 pr-9 text-sm text-gray-900 placeholder:text-gray-400">
                                <button type="button" id="settingsSearchClear"
                                        class="absolute right-1.5 top-1/2 hidden h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-lg leading-none text-gray-500 hover:bg-gray-100"
                                        aria-label="Clear settings search">&times;</button>
                            </div>
                            <span id="settingsSearchCount" class="w-24 shrink-0 text-right text-[11px] font-bold text-gray-600"></span>
                        </div>
                        <nav id="settingsSectionNav" class="flex gap-1 overflow-x-auto whitespace-nowrap text-[11px] sm:text-xs">
                            <a href="#group-branding" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Branding</a>
                            <a href="#group-appearance" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Appearance</a>
                            <a href="#group-announcements" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Announcements</a>
                            <a href="#group-maintenance-overlay" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Maintenance Overlay</a>
                            <a href="#group-catalog" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Service Catalog</a>
                            <a href="#group-data-defaults" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Data Defaults</a>
                            <a href="#group-data-pricing" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Data Pricing</a>
                            <a href="#group-wallet" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Wallet</a>
                            <a href="#group-referral" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Referral</a>
                            <a href="#group-provider" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Provider</a>
                            <a href="#group-markup" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Markup</a>
                            <a href="#group-recharge-card" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Recharge Cards</a>
                            <a href="#group-exam-prices" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Exam Prices</a>
                            <a href="#group-identity-services" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">NIN/BVN</a>
                            <a href="#group-manual-identity" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Manual Requests</a>
                            <a href="#group-sms-gateway" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">SMS Gateway</a>
                            <a href="#group-sms-gateway" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">SMS Gateway</a>
                            <a href="#group-app-download" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">App Download</a>
                            <a href="#group-footer-social" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Footer & Social</a>
                            <a href="#group-error-codes" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-gray-100">Error Codes</a>
                        </nav>
                    </div>
                </div>

                <div id="settingsSearchEmpty" class="hidden rounded-2xl border border-gray-200 bg-slate-50 p-5 text-center text-sm font-semibold text-gray-600">
                    No settings match that search. Try a shorter word, or clear the box to see everything again.
                </div>

            {{-- Branding --}}
            <div id="group-branding" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Branding</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Site Name</label>
                        <input name="site_name" value="{{ old('site_name', $settings['site_name'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">WhatsApp Link</label>
                        <input name="whatsapp_link" value="{{ old('whatsapp_link', $settings['whatsapp_link'] ?? whatsapp_link()) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="text-xs text-gray-500 mt-1">Example: https://wa.me/2348000000000</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">WhatsApp Channel Link</label>
                        <input name="whatsapp_channel_link" value="{{ old('whatsapp_channel_link', $settings['whatsapp_channel_link'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="text-xs text-gray-500 mt-1">Example: https://whatsapp.com/channel/XXXXXXXXXXX</div>
                    </div>
                    @php
                        // Every brand image has its own slot so the loading animation can
                        // never be forced to reuse the wordmark shown in the header.
                        $brandSlots = [
                            ['field' => 'logo', 'key' => 'logo_url', 'label' => 'Site logo (PNG)', 'box' => 'w-14 h-14', 'hint' => 'Signed-in pages: header, sidebar and dashboard.', 'none' => 'No logo'],
                            ['field' => 'login_logo', 'key' => 'login_logo_url', 'label' => 'Login page logo (PNG)', 'box' => 'w-14 h-14', 'hint' => 'Login, register and password pages. Left empty, those pages use the site logo.', 'none' => 'Uses site logo'],
                            ['field' => 'loader_logo', 'key' => 'loader_logo_url', 'label' => 'Loading animation logo', 'box' => 'w-12 h-12', 'hint' => 'The square mark inside the splash and page loader. Upload a square image — it never falls back to the site logo.', 'none' => 'Built-in mark'],
                            ['field' => 'favicon', 'key' => 'favicon_url', 'label' => 'Favicon (PNG/ICO)', 'box' => 'w-10 h-10', 'hint' => 'Browser tab icon and the saved home-screen icon.', 'none' => 'No icon'],
                        ];
                    @endphp
                    @foreach($brandSlots as $slot)
                        @php $current = logo_asset_url($slot['key']); @endphp
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">{{ $slot['label'] }}</label>
                            <input type="file" name="{{ $slot['field'] }}" accept="image/*"
                                   id="{{ $slot['field'] }}Input"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <div class="text-xs text-gray-500 mt-1">{{ $slot['hint'] }}</div>
                            @if(!empty($settings[$slot['key']] ?? null))
                                <div class="mt-2 text-xs text-gray-500">Current: <span class="font-bold">{{ $settings[$slot['key']] }}</span></div>
                            @endif
                            <div class="mt-3 flex items-center gap-3">
                                <div class="{{ $slot['box'] }} rounded-2xl bg-slate-100 border border-gray-200 overflow-hidden flex items-center justify-center">
                                    <img id="{{ $slot['field'] }}Preview"
                                         src="{{ $current }}"
                                         class="w-full h-full object-cover {{ $current === '' ? 'hidden' : '' }}"
                                         alt="{{ $slot['label'] }} preview">
                                    <span id="{{ $slot['field'] }}Placeholder"
                                          class="text-[10px] text-gray-500 text-center px-1 {{ $current === '' ? '' : 'hidden' }}">{{ $slot['none'] }}</span>
                                </div>
                                <div class="text-xs text-gray-500">Preview</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Appearance / colour theme --}}
            <div id="group-appearance" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Appearance</div>
                <div class="text-xs text-gray-500 mt-1">Pick the colours the whole site wears. Both themes are already built, so switching is instant once you save — nothing else about the site changes.</div>

                @php
                    $activeTheme = old('site_theme', $settings['site_theme'] ?? 'navy');
                    if (!array_key_exists($activeTheme, site_themes())) {
                        $activeTheme = 'navy';
                    }
                @endphp

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    @foreach(site_themes() as $themeKey => $theme)
                        <label class="relative flex cursor-pointer flex-col gap-3 rounded-2xl border p-4 transition has-[:checked]:border-orange-500 has-[:checked]:ring-2 has-[:checked]:ring-orange-500/30 border-gray-300 bg-white">
                            <input type="radio" name="site_theme" value="{{ $themeKey }}" class="sr-only"
                                   @checked($activeTheme === $themeKey)>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-extrabold text-gray-900">{{ $theme['name'] }}</span>
                                @if($activeTheme === $themeKey)
                                    <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-bold text-orange-700">In use</span>
                                @endif
                            </div>
                            <div class="flex gap-1.5" aria-hidden="true">
                                @foreach($theme['swatches'] as $swatch)
                                    <span class="h-7 w-7 rounded-full border border-gray-200" style="background: {{ $swatch }}"></span>
                                @endforeach
                            </div>
                            <div class="text-xs text-gray-600">{{ $theme['summary'] }}</div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Announcements & Marquee --}}
            <div id="group-announcements" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Announcements & Marquee</div>
                <div class="grid grid-cols-1 gap-6 mt-3">
                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">Marquee Speed (seconds per loop)</label>
                            <input type="number" step="0.5" min="5" max="120" name="marquee_speed_seconds"
                                   value="{{ old('marquee_speed_seconds', $settings['marquee_speed_seconds'] ?? '30') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <div class="text-xs text-gray-500 mt-1">Higher values make the marquee move slower. This applies to all marquee areas.</div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Landing Page (Home)</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-gray-800/80">Marquee Text</label>
                                <textarea name="home_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">{{ old('home_marquee_message', $settings['home_marquee_message'] ?? $settings['popup_message'] ?? 'Need NIN services? Click WhatsApp Support to chat with us instantly.') }}</textarea>
                            </div>

                            <div class="flex items-center gap-3">
                                <input id="home_popup_enabled" type="checkbox" name="home_popup_enabled" value="1"
                                       @checked(old('home_popup_enabled', $settings['home_popup_enabled'] ?? $settings['popup_enabled'] ?? '1') == '1')
                                       class="w-5 h-5 rounded border-gray-300 bg-white">
                                <label for="home_popup_enabled" class="text-sm text-gray-800/80 font-bold">Enable Popup</label>
                            </div>

                            <div>
                                <label class="text-sm font-bold text-gray-800/80">Popup Message</label>
                                @php
                                    $homePopupValue = old('home_popup_message', $settings['home_popup_message'] ?? $settings['popup_message'] ?? 'Need NIN services? Tap the WhatsApp button to chat with us.');
                                @endphp
                                <x-admin.popup-rich-editor
                                    name="home_popup_message"
                                    id="home_popup_message"
                                    :value="$homePopupValue"
                                    placeholder="Type the home popup message..."
                                    helper="Use the toolbar to control bold, italic, alignment, lists, and other popup formatting."
                                />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Dashboard</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-gray-800/80">Marquee Text</label>
                                <textarea name="dashboard_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">{{ old('dashboard_marquee_message', $settings['dashboard_marquee_message'] ?? 'Need NIN services? Click WhatsApp Support to chat with us instantly.') }}</textarea>
                            </div>

                            <div class="flex items-center gap-3">
                                <input id="dashboard_popup_enabled" type="checkbox" name="dashboard_popup_enabled" value="1"
                                       @checked(old('dashboard_popup_enabled', $settings['dashboard_popup_enabled'] ?? '1') == '1')
                                       class="w-5 h-5 rounded border-gray-300 bg-white">
                                <label for="dashboard_popup_enabled" class="text-sm text-gray-800/80 font-bold">Enable Popup</label>
                            </div>

                            <div>
                                <label class="text-sm font-bold text-gray-800/80">Popup Message</label>
                                @php
                                    $dashboardPopupValue = old('dashboard_popup_message', $settings['dashboard_popup_message'] ?? 'For NIN services (New enrolment, correction, printing, etc.) click the WhatsApp Support button to chat with us instantly.');
                                @endphp
                                <x-admin.popup-rich-editor
                                    name="dashboard_popup_message"
                                    id="dashboard_popup_message"
                                    :value="$dashboardPopupValue"
                                    placeholder="Type the dashboard popup message..."
                                    helper="Formatting here is preserved in the popup shown to users."
                                />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Fund Wallet Page</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-gray-800/80">Marquee Text</label>
                                <textarea name="fund_wallet_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">{{ old('fund_wallet_marquee_message', $settings['fund_wallet_marquee_message'] ?? 'Flutterwave tip: Use checkout for instant card or bank payment, or generate your virtual account and fund it by transfer.') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Maintenance Overlay --}}
            <div id="group-maintenance-overlay" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Maintenance Overlay</div>
                <div class="text-xs text-gray-500 mt-1">
                    Disabled by default. When enabled, a countdown overlay is shown to users until the end time.
                </div>

                <div class="rounded-2xl border border-gray-200 p-4 mt-3">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="col-span-2 lg:col-span-4 flex items-center gap-3">
                            <input id="maintenance_overlay_enabled" type="checkbox" name="maintenance_overlay_enabled" value="1"
                                   @checked(old('maintenance_overlay_enabled', $settings['maintenance_overlay_enabled'] ?? '0') == '1')
                                   class="w-5 h-5 rounded border-gray-300 bg-white">
                            <label for="maintenance_overlay_enabled" class="text-sm text-gray-800/80 font-bold">
                                Enable Maintenance Overlay
                            </label>
                        </div>

                        <div>
                            <label class="text-sm font-bold text-gray-800/80">End Time</label>
                            @php
                                $maintenanceEndAtValue = old('maintenance_overlay_end_at', '');
                                if ($maintenanceEndAtValue === '') {
                                    $rawMaintenanceEndAt = trim((string) ($settings['maintenance_overlay_end_at'] ?? ''));
                                    if ($rawMaintenanceEndAt !== '') {
                                        try {
                                            $maintenanceEndAtValue = \Illuminate\Support\Carbon::parse($rawMaintenanceEndAt)->format('Y-m-d\TH:i');
                                        } catch (\Throwable $e) {
                                            $maintenanceEndAtValue = '';
                                        }
                                    }
                                }
                            @endphp
                            <input type="datetime-local"
                                   name="maintenance_overlay_end_at"
                                   value="{{ $maintenanceEndAtValue }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <div class="text-xs text-gray-500 mt-1">Set to current time plus your preferred minutes.</div>
                        </div>

                        <div>
                            <label class="text-sm font-bold text-gray-800/80">Overlay Message</label>
                            <textarea name="maintenance_overlay_message" rows="3"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                      placeholder="We are currently running an update. Please hold on while we finish.">{{ old('maintenance_overlay_message', $settings['maintenance_overlay_message'] ?? 'We are currently running an update. Please hold on while we finish.') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Service Catalog --}}
            <div id="group-catalog" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Service Catalog (Advanced)</div>
                <div class="text-xs text-gray-500 mt-1">Format: one per line as <code>service_id|Display Name</code>. Leave blank to use defaults.</div>

                <div class="grid grid-cols-1 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Airtime Services</label>
                        <textarea name="services_airtime" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="mtn|MTN Airtime&#10;airtel|Airtel Airtime&#10;glo|GLO Airtime&#10;etisalat|9mobile Airtime">{{ old('services_airtime', $settings['services_airtime'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Data Services</label>
                        <textarea name="services_data" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="mtn_gifting|MTN Data (Gifting)&#10;mtn_awoof|MTN Awoof Data (Cheap)&#10;airtel_sme|Airtel Data (SME)&#10;glo_data|Glo Data&#10;etisalat_data|9mobile Data">{{ old('services_data', $settings['services_data'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Cable Services</label>
                        <textarea name="services_cable" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="dstv|DSTV Subscription&#10;gotv|GOTV Subscription&#10;startimes|Startimes Subscription">{{ old('services_cable', $settings['services_cable'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Electricity Services</label>
                        <textarea name="services_electricity" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="ikeja-electric|Ikeja Electric (IKEDC)&#10;eko-electric|Eko Electric (EKEDC)&#10;abuja-electric|Abuja Electric (AEDC)">{{ old('services_electricity', $settings['services_electricity'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Education Services</label>
                        <textarea name="services_education" rows="4"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="jamb|JAMB PIN (UTME & Direct Entry)&#10;waec|WAEC Result Checker PIN&#10;neco|NECO Result Checker PIN&#10;nabteb|NABTEB Result Checker PIN">{{ old('services_education', $settings['services_education'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Premium App Services</label>
                        <textarea name="services_premium" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="canva|Canva Pro">{{ old('services_premium', $settings['services_premium'] ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Data Defaults --}}
            <div id="group-data-defaults" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Data Defaults</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">MTN Quick Pick Default</label>
                        <select name="data_default_mtn_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            @php
                                $defaultMtnService = old('data_default_mtn_service', $settings['data_default_mtn_service'] ?? 'mtn_gifting');
                            @endphp
                            <option value="mtn_gifting" @selected($defaultMtnService === 'mtn_gifting')>
                                MTN Data (Gifting)
                            </option>
                            <option value="mtn_awoof" @selected($defaultMtnService === 'mtn_awoof')>
                                MTN Awoof Data (Cheap)
                            </option>
                            <option value="mtn_sme" @selected($defaultMtnService === 'mtn_sme')>
                                MTN Data (SME)
                            </option>
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Controls the MTN quick pick button on the data page.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Airtel Quick Pick Default</label>
                        <select name="data_default_airtel_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            @php
                                $defaultAirtelService = old('data_default_airtel_service', $settings['data_default_airtel_service'] ?? 'airtel_sme');
                            @endphp
                            <option value="airtel_sme" @selected($defaultAirtelService === 'airtel_sme')>
                                Airtel Data (SME)
                            </option>
                            <option value="airtel_cg" @selected($defaultAirtelService === 'airtel_cg')>
                                Airtel Data (CG)
                            </option>
                            <option value="airtel_gifting" @selected($defaultAirtelService === 'airtel_gifting')>
                                Airtel Data (Gifting)
                            </option>
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Controls the Airtel quick pick button on the data page.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Glo Quick Pick Default</label>
                        <select name="data_default_glo_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            @php
                                $defaultGloService = old('data_default_glo_service', $settings['data_default_glo_service'] ?? 'glo_data');
                            @endphp
                            <option value="glo_data" @selected($defaultGloService === 'glo_data')>
                                Glo Data
                            </option>
                            <option value="glo_sme" @selected($defaultGloService === 'glo_sme')>
                                Glo Data (SME)
                            </option>
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Controls the Glo quick pick button on the data page.</div>
                    </div>

                    <div class="sm:col-span-2 rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Data Service On/Off</div>
                        <div class="text-xs text-gray-500 mt-1">
                            Turn individual network services on or off for the Data page.
                        </div>
                        @php
                            $dataServiceGroups = [
                                'MTN' => [
                                    'mtn_awoof' => ['label' => 'MTN Awoof Data (Cheap)', 'default' => '1'],
                                    'mtn_gifting' => ['label' => 'MTN Data (Gifting)', 'default' => '1'],
                                    'mtn_sme' => ['label' => 'MTN Data (SME)', 'default' => '1'],
                                    'mtn_cg' => ['label' => 'MTN Data (Corporate)', 'default' => '0'],
                                    'mtn_cg_lite' => ['label' => 'MTN Data (CG Lite)', 'default' => '0'],
                                    'mtn_coupon' => ['label' => 'MTN Coupon', 'default' => '0'],
                                    'mtncg' => ['label' => 'MTN CG', 'default' => '0'],
                                ],
                                'Airtel' => [
                                    'airtel_sme' => ['label' => 'Airtel Data (SME)', 'default' => '1'],
                                    'airtel_cg' => ['label' => 'Airtel Data (CG)', 'default' => '1'],
                                    'airtel_gifting' => ['label' => 'Airtel Data (Gifting)', 'default' => '1'],
                                ],
                                'Glo' => [
                                    'glo_data' => ['label' => 'Glo Data', 'default' => '1'],
                                    'glo_sme' => ['label' => 'Glo Data (SME)', 'default' => '1'],
                                ],
                                '9mobile' => [
                                    'etisalat_data' => ['label' => '9mobile Data', 'default' => '1'],
                                ],
                            ];
                        @endphp
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-3">
                            @foreach($dataServiceGroups as $networkLabel => $networkServices)
                                <div class="rounded-2xl border border-gray-200 p-4">
                                    <div class="font-bold text-gray-500">{{ $networkLabel }}</div>
                                    <div class="mt-3 space-y-2">
                                        @foreach($networkServices as $slug => $meta)
                                            @php
                                                $key = 'data_service_enabled_' . $slug;
                                                $checked = old($key, $settings[$key] ?? $meta['default']) === '1';
                                            @endphp
                                            <label class="flex items-center justify-between gap-3 text-sm">
                                                <span class="text-gray-500">{{ $meta['label'] }}</span>
                                                <input type="checkbox"
                                                       name="{{ $key }}"
                                                       value="1"
                                                       @checked($checked)
                                                       class="w-5 h-5 rounded border-gray-300 bg-white">
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Data Plan Pricing --}}
            <div id="group-data-pricing" class="scroll-mt-44">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-lg font-extrabold">Website Selling Prices</div>
                        <div class="text-xs text-gray-500 mt-1">
                            Every plan a customer opens is fetched from GSUBZ and its price recorded, and the price is re-checked at checkout, so selling prices follow the provider. Editing a value here marks that plan as custom and keeps your price. Use sync to pull the whole catalogue now, including services nobody has browsed recently.
                        </div>
                    </div>
                    <button type="submit"
                            form="syncProviderPricesForm"
                            class="rounded-xl border border-gray-200 px-4 py-2.5 text-xs font-bold text-gray-500 hover:bg-gray-100">
                        Sync Latest GSUBZ Prices
                    </button>
                </div>

                <div class="mt-4 space-y-5">
                    @foreach(($pricingServiceGroups ?? []) as $groupLabel => $services)
                        <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                            <div class="font-extrabold text-gray-500">{{ $groupLabel }}</div>
                            <div class="mt-4 grid grid-cols-1 gap-4">
                                @foreach($services as $slug => $label)
                                    @php
                                        $rows = $providerPlanPrices[$slug] ?? collect();
                                    @endphp
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <div class="font-bold text-gray-500">{{ $label }}</div>
                                                <div class="text-xs text-gray-500">{{ $slug }}</div>
                                            </div>
                                            <div class="text-xs text-gray-500">{{ $rows->count() }} plan{{ $rows->count() === 1 ? '' : 's' }}</div>
                                        </div>

                                        @if($rows->isEmpty())
                                            <div class="mt-3 rounded-xl border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
                                                No GSUBZ plans stored yet. Click sync above; any new provider plans will be added here automatically.
                                            </div>
                                        @else
                                            <div class="mt-3 overflow-x-auto">
                                                <table class="min-w-[720px] w-full text-sm">
                                                    <thead class="text-left text-[11px] uppercase tracking-wide text-gray-500">
                                                    <tr>
                                                        <th class="py-2 pr-3">Plan</th>
                                                        <th class="py-2 px-3">Plan ID</th>
                                                        <th class="py-2 px-3">GSUBZ Price</th>
                                                        <th class="py-2 px-3">Website Price</th>
                                                        <th class="py-2 pl-3">Synced</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    @foreach($rows as $priceRow)
                                                        <tr class="border-t border-gray-200">
                                                            <td class="py-3 pr-3 text-gray-500">{{ $priceRow->plan_name ?: $priceRow->plan_id }}</td>
                                                            <td class="py-3 px-3 font-mono text-xs text-gray-500">{{ $priceRow->plan_id }}</td>
                                                            <td class="py-3 px-3 text-gray-500">&#8358;{{ number_format((float) $priceRow->provider_price, 2) }}</td>
                                                            <td class="py-3 px-3">
                                                                <input type="number"
                                                                       min="0"
                                                                       step="0.01"
                                                                       name="provider_plan_prices[{{ $priceRow->id }}][selling_price]"
                                                                       value="{{ old('provider_plan_prices.'.$priceRow->id.'.selling_price', number_format((float) $priceRow->selling_price, 2, '.', '')) }}"
                                                                       class="w-36 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                                                            </td>
                                                            <td class="py-3 pl-3 text-xs text-gray-500">
                                                                {{ $priceRow->last_synced_at?->diffForHumans() ?? 'Not synced' }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Wallet Funding --}}
            <div id="group-wallet" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Wallet Funding</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Flutterwave Funding Fee (₦)</label>
                        <input type="number" step="0.01" name="wallet_funding_fee"
                               value="{{ old('wallet_funding_fee', $settings['wallet_funding_fee'] ?? '50') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="text-xs text-gray-500 mt-1">This fee is deducted from every Flutterwave deposit.</div>
                    </div>

                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Flutterwave Webhook URL</label>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <input type="text" readonly value="{{ url(route('flutterwave.webhook', absolute: false)) }}"
                                   class="flex-1 min-w-[14rem] px-4 py-3 rounded-2xl bg-slate-50 border border-gray-300 text-gray-800">
                            <button type="button" class="btn-outline px-4 py-2 text-sm font-bold"
                                    data-copy-text="{{ url(route('flutterwave.webhook', absolute: false)) }}"
                                    data-copy-label="Copy" data-copy-done="Copied">Copy</button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            Paste this into Flutterwave → Settings → Webhooks. A wallet top-up paid by bank
                            transfer into a virtual account is only credited when Flutterwave posts to this
                            address, so an unregistered webhook leaves real money sitting in limbo. Set
                            FLUTTERWAVE_SECRET_HASH in .env to the webhook hash from the same Flutterwave page,
                            or every call here is rejected.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Referral System --}}
            <div id="group-referral" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Referral System</div>
                <div class="text-xs text-gray-500 mt-1">
                    Separate from user discount. Referrer earns commission when qualified referrals purchase services.
                </div>

                <div class="mt-3 rounded-2xl border border-gray-200 p-4 space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <label for="referral_system_enabled" class="text-sm font-bold text-gray-800/80">Enable Referral System</label>
                        <input id="referral_system_enabled" type="checkbox" name="referral_system_enabled" value="1"
                               @checked(old('referral_system_enabled', $settings['referral_system_enabled'] ?? '1') === '1')
                               class="w-5 h-5 rounded border-gray-300 bg-white">
                    </div>

                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Default Percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="referral_default_percent"
                               value="{{ old('referral_default_percent', $settings['referral_default_percent'] ?? '1') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="text-xs text-gray-500 mt-1">Used if a specific service percentage is not set.</div>
                    </div>

                    @php
                        $referralServices = [
                            'airtime' => 'Airtime',
                            'data' => 'Data',
                            'cable' => 'Cable TV',
                            'electricity' => 'Electricity',
                            'exam' => 'Exam Pins',
                            'recharge_card' => 'Recharge Cards',
                            'premium' => 'Premium Apps',
                        ];
                    @endphp

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach($referralServices as $serviceKey => $label)
                            @php
                                $enabledKey = 'referral_enabled_' . $serviceKey;
                                $percentKey = 'referral_percent_' . $serviceKey;
                            @endphp
                            <div class="rounded-2xl border border-gray-200 p-3 space-y-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-bold text-gray-500">{{ $label }}</div>
                                    <input type="checkbox"
                                           name="{{ $enabledKey }}"
                                           value="1"
                                           @checked(old($enabledKey, $settings[$enabledKey] ?? '1') === '1')
                                           class="w-5 h-5 rounded border-gray-300 bg-white">
                                </div>
                                <div>
                                    <label class="text-xs text-gray-800/60">Percentage (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="{{ $percentKey }}"
                                           value="{{ old($percentKey, $settings[$percentKey] ?? '1') }}"
                                           class="w-full mt-1 px-3 py-2 rounded-xl bg-white border border-gray-300 text-gray-900">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Provider --}}
            <div id="group-provider" class="scroll-mt-44">
                <div class="text-lg font-extrabold">API Provider</div>
                <div class="text-xs text-gray-500 mt-1">
                    Switch active provider and keep separate credentials and service-ID maps for each provider profile.
                </div>

                @php
                    // Resolved exactly the way App\Services\GsubzApi resolves it at runtime.
                    $activeProvider = trim((string) setting('provider', 'gsubz'));
                    $activeProviderKey = $activeProvider === 'alt'
                        ? trim((string) setting('provider_alt_api_key', config('services.alt.key', '')))
                        : trim((string) setting('provider_gsubz_api_key', config('services.gsubz.key', '')));

                    $missingIntegrations = [];
                    if ($activeProvider !== 'mock' && $activeProviderKey === '') {
                        $missingIntegrations[] = ($activeProvider === 'alt' ? 'Alternative API' : 'GSUBZ')
                            .' key — airtime, data, cable, electricity, exam and premium apps';
                    }
                    if (trim((string) setting('nin_api_key', config('services.nin.key', ''))) === '') {
                        $missingIntegrations[] = identity_verify_mode('nin') === 'automatic'
                            ? 'NIN key — NIN search, print, reports and validation'
                            : 'NIN key — only needed if NIN verification is switched back to automatic';
                    }
                    if (trim((string) setting('bvn_api_key', config('services.bvn.key', ''))) === '') {
                        $missingIntegrations[] = identity_verify_mode('bvn') === 'automatic'
                            ? 'BVN key — BVN verify and retrieve'
                            : 'BVN key — only needed if BVN verification is switched back to automatic';
                    }
                    if (trim((string) config('services.flutterwave.secret_key', '')) === '') {
                        $missingIntegrations[] = 'Flutterwave secret key — wallet funding and virtual accounts';
                    }
                @endphp
                @if ($missingIntegrations !== [])
                    <div class="mt-3 rounded-2xl border px-4 py-3 text-sm" style="border-color:#e8b48f;background:#fdf3ec;color:#8a3d0b;">
                        <strong>These services cannot run yet</strong> because no key is saved for them:
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($missingIntegrations as $missing)
                                <li>{{ $missing }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2">Keys are read from this page only — never from the browser. Choose
                            <strong>Mock (Test Mode)</strong> above to rehearse orders without a provider.</p>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Active Provider</label>
                        <select name="provider"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            @php $provider = old('provider', $settings['provider'] ?? 'gsubz'); @endphp
                            <option value="gsubz" @selected($provider === 'gsubz')>GSUBZ (Live)</option>
                            <option value="alt" @selected($provider === 'alt')>Alternative API</option>
                            <option value="mock" @selected($provider === 'mock')>Mock (Test Mode)</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">GSUBZ Credentials</div>
                        <div class="mt-3 space-y-3">
                            <div>
                                <label class="text-xs text-gray-800/60">GSUBZ Base URL</label>
                                <input name="provider_gsubz_base_url"
                                       value="{{ old('provider_gsubz_base_url', $settings['provider_gsubz_base_url'] ?? 'https://api.gsubz.com') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            </div>
                            <div>
                                <label class="text-xs text-gray-800/60">GSUBZ API Key</label>
                                <input name="provider_gsubz_api_key"
                                       value="{{ old('provider_gsubz_api_key', $settings['provider_gsubz_api_key'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Alternative Provider Credentials</div>
                        <div class="mt-3 space-y-3">
                            <div>
                                <label class="text-xs text-gray-800/60">Alternative Base URL</label>
                                <input name="provider_alt_base_url"
                                       value="{{ old('provider_alt_base_url', $settings['provider_alt_base_url'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                       placeholder="https://api.example.com">
                            </div>
                            <div>
                                <label class="text-xs text-gray-800/60">Alternative API Key</label>
                                <input name="provider_alt_api_key"
                                       value="{{ old('provider_alt_api_key', $settings['provider_alt_api_key'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-gray-200 p-4">
                    <div class="font-bold text-gray-500">NIN API Credentials</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                        <div>
                            <label class="text-xs text-gray-800/60">NIN Base URL</label>
                            <input name="nin_base_url"
                                   value="{{ old('nin_base_url', $settings['nin_base_url'] ?? 'https://confirmident.com.ng/api') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        </div>
                        <div>
                            <label class="text-xs text-gray-800/60">NIN API Key</label>
                            <input name="nin_api_key"
                                   value="{{ old('nin_api_key', $settings['nin_api_key'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        </div>
                        <div>
                            <label class="text-xs text-gray-800/60">NIN Print Endpoint (Optional)</label>
                            <input name="nin_print_endpoint"
                                   value="{{ old('nin_print_endpoint', $settings['nin_print_endpoint'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                   placeholder="/Verify or full URL">
                        </div>
                        <div>
                            <label class="text-xs text-gray-800/60">NIN Reports Endpoint (Optional)</label>
                            <input name="nin_reports_endpoint"
                                   value="{{ old('nin_reports_endpoint', $settings['nin_reports_endpoint'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                   placeholder="/Verify/load or full URL">
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Verification works with documented endpoints. Slip print and reports require provider endpoints from JHTech.
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-gray-200 p-4">
                    <div class="font-bold text-gray-500">Per-Provider Service Map Profiles</div>
                    <div class="text-xs text-gray-500 mt-1">
                        Format: one per line as <code>service_slug|provider_service_id</code>. These override single-field mappings.
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-3">
                        <div>
                            <label class="text-xs text-gray-800/60">GSUBZ Map Profile</label>
                            <textarea name="service_map_profile_gsubz" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                      placeholder="mtn_gifting|mtn_gifting&#10;jamb|jamb">{{ old('service_map_profile_gsubz', $settings['service_map_profile_gsubz'] ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs text-gray-800/60">Alternative Map Profile</label>
                            <textarea name="service_map_profile_alt" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                      placeholder="mtn_gifting|provider_mtn_data">{{ old('service_map_profile_alt', $settings['service_map_profile_alt'] ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs text-gray-800/60">Mock Map Profile</label>
                            <textarea name="service_map_profile_mock" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                      placeholder="jamb|mock_jamb">{{ old('service_map_profile_mock', $settings['service_map_profile_mock'] ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Service ID Overrides --}}
            <div id="group-service-map" class="hidden scroll-mt-44" aria-hidden="true">
                <div class="text-lg font-extrabold">Service ID Overrides</div>
                <div class="text-xs text-gray-500 mt-1">Leave blank to use the default service ID in code. These fields are provider service IDs, not plan prices.</div>

                @php
                    $airtimeMap = [
                        'mtn' => 'MTN Airtime',
                        'airtel' => 'Airtel Airtime',
                        'glo' => 'Glo Airtime',
                        'etisalat' => '9mobile Airtime',
                    ];
                    $rechargeCardMap = [
                        'card_mtn' => 'Recharge Card (MTN)',
                        'card_airtel' => 'Recharge Card (Airtel)',
                        'card_glo' => 'Recharge Card (Glo)',
                        'card_etisalat' => 'Recharge Card (9mobile)',
                    ];
                    $dataMap = [
                        'mtn_gifting' => 'MTN Data (Gifting)',
                        'mtn_sme' => 'MTN Data (SME)',
                        'mtn_awoof' => 'MTN Awoof Data (Cheap)',
                        'mtn_cg' => 'MTN Data (Corporate)',
                        'mtn_cg_lite' => 'MTN Data (CG Lite)',
                        'mtn_coupon' => 'MTN Coupon',
                        'mtncg' => 'MTN CG',
                        'airtel_sme' => 'Airtel Data (SME)',
                        'airtel_cg' => 'Airtel Data (CG)',
                        'airtel_gifting' => 'Airtel Data (Gifting)',
                        'glo_data' => 'Glo Data',
                        'glo_sme' => 'Glo Data (SME)',
                        'etisalat_data' => '9mobile Data',
                    ];
                    $cableMap = [
                        'dstv' => 'DSTV',
                        'gotv' => 'GOTV',
                        'startimes' => 'Startimes',
                    ];
                    $electricityMap = [
                        'abuja-electric' => 'Abuja Electric (AEDC)',
                        'eko-electric' => 'Eko Electric (EKEDC)',
                        'ibadan-electric' => 'Ibadan Electric (IBEDC)',
                        'ikeja-electric' => 'Ikeja Electric (IKEDC)',
                        'jos-electric' => 'Jos Electric (JED)',
                        'kaduna-electric' => 'Kaduna Electric (KAEDCO)',
                        'kano-electric' => 'Kano Electric (KEDCO)',
                        'phed-electric' => 'Port Harcourt Electric (PHED)',
                        'yola-electric' => 'Yola Electric (YEDC)',
                        'benin-electric' => 'Benin Electric (BEDC)',
                        'enugu-electric' => 'Enugu Electric (EEDC)',
                    ];
                    $digitalMap = [
                        'canva' => 'Canva Pro',
                    ];
                @endphp

                <div class="mt-4 space-y-5">
                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Airtime</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($airtimeMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Recharge Cards</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($rechargeCardMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ str_replace('card_', '', $slug) }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Data</div>
                        <div class="text-xs text-gray-500 mt-1">For Awoof, keep this as <code>mtn_awoof</code> or blank. Set customer profit under Customer Markup.</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($dataMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Cable TV</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($cableMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Electricity</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($electricityMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Exam Pins</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @php
                                $examMap = [
                                    'service_exam_jamb' => 'JAMB Service ID',
                                    'service_exam_waec' => 'WAEC Service ID',
                                    'service_exam_neco' => 'NECO Service ID',
                                    'service_exam_nabteb' => 'NABTEB Service ID',
                                ];
                            @endphp
                            @foreach($examMap as $key => $label)
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ str_replace('service_exam_', '', $key) }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-bold text-gray-500">Social & Premium</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($digitalMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Markups --}}
            <div id="group-markup" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Customer Markup (₦)</div>
                <div class="text-xs text-gray-500 mt-1">This is added to provider price. This becomes your profit.</div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Airtime Markup</label>
                        <input type="number" step="0.01" name="markup_airtime"
                               value="{{ old('markup_airtime', $settings['markup_airtime'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Data Markup (Legacy)</label>
                        <input type="number" step="0.01" name="markup_data"
                               value="{{ old('markup_data', $settings['markup_data'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="text-xs text-gray-500 mt-1">Use Data Plan Selling Prices for customer data prices.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Cable Markup</label>
                        <input type="number" step="0.01" name="markup_cable"
                               value="{{ old('markup_cable', $settings['markup_cable'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Electricity Markup</label>
                        <input type="number" step="0.01" name="markup_electricity"
                               value="{{ old('markup_electricity', $settings['markup_electricity'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Exam Markup</label>
                        <input type="number" step="0.01" name="markup_exam"
                               value="{{ old('markup_exam', $settings['markup_exam'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Recharge Card Markup</label>
                        <input type="number" step="0.01" name="markup_recharge_card"
                               value="{{ old('markup_recharge_card', $settings['markup_recharge_card'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Premium Apps Markup</label>
                        <input type="number" step="0.01" name="markup_premium"
                               value="{{ old('markup_premium', $settings['markup_premium'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Services Markup</label>
                        <input type="number" step="0.01" name="markup_bvn"
                               value="{{ old('markup_bvn', $settings['markup_bvn'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Print Markup</label>
                        <input type="number" step="0.01" name="markup_nin_print"
                               value="{{ old('markup_nin_print', $settings['markup_nin_print'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Validation Markup</label>
                        <input type="number" step="0.01" name="markup_nin_validation"
                               value="{{ old('markup_nin_validation', $settings['markup_nin_validation'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Airtime Discount (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="airtime_discount_percent"
                               value="{{ old('airtime_discount_percent', $settings['airtime_discount_percent'] ?? '2') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>
            </div>

            {{-- Exam Base Prices --}}
            <div id="group-exam-prices" class="scroll-mt-44">
                @php
                    // Pre-filled with the figure the store actually charges, so
                    // saving this panel without touching a field cannot silently
                    // price an exam at zero and lock customers out of buying it.
                    $examDefaults = exam_price_defaults();
                @endphp
                <div class="text-lg font-extrabold">Exam Base Prices (₦)</div>
                <div class="text-xs text-gray-500 mt-1">Set provider/base price here. Customer pays base + exam markup.</div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">JAMB Base</label>
                        <input type="number" step="0.01" name="price_exam_jamb"
                               value="{{ old('price_exam_jamb', $settings['price_exam_jamb'] ?? $examDefaults['jamb']) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">WAEC Base</label>
                        <input type="number" step="0.01" name="price_exam_waec"
                               value="{{ old('price_exam_waec', $settings['price_exam_waec'] ?? $examDefaults['waec']) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NECO Base</label>
                        <input type="number" step="0.01" name="price_exam_neco"
                               value="{{ old('price_exam_neco', $settings['price_exam_neco'] ?? $examDefaults['neco']) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NABTEB Base</label>
                        <input type="number" step="0.01" name="price_exam_nabteb"
                               value="{{ old('price_exam_nabteb', $settings['price_exam_nabteb'] ?? $examDefaults['nabteb']) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Education Transaction Charge</label>
                        <input type="number" step="0.01" name="price_exam_transaction_fee"
                               value="{{ old('price_exam_transaction_fee', $settings['price_exam_transaction_fee'] ?? $examDefaults['fee']) }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>
            </div>

            <div id="group-identity-services" class="scroll-mt-44">
                <div class="text-lg font-extrabold">NIN & BVN Services</div>
                <div class="text-xs text-gray-500 mt-1">Set pricing and endpoints. Endpoints can be relative (`/path`) or full URL.</div>
                <div class="text-xs text-gray-500 mt-1">The cost under each price is what the provider charges this account per job, so a price set at or below it is worked at a loss.</div>

                @php
                    $verifyModes = ['automatic' => 'Automatic - the provider API answers', 'manual' => 'Manual - our team completes it'];
                @endphp
                <div class="mt-3 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="text-sm font-extrabold text-gray-900">Who runs a verification</div>
                    <div class="text-xs text-gray-600 mt-1">
                        Manual puts the paid request in your Manual Requests queue and an admin posts the result to the
                        customer's receipt. Automatic calls the provider and answers on the spot. The customer is
                        charged the same price either way. Switch to manual if the provider stops answering.
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        @foreach(['nin' => 'NIN verification', 'bvn' => 'BVN verification'] as $verifyService => $verifyLabel)
                            @php $verifyModeKey = $verifyService.'_verify_mode'; @endphp
                            <div>
                                <label class="text-sm font-bold text-gray-800/80">{{ $verifyLabel }}</label>
                                <select name="{{ $verifyModeKey }}"
                                        class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                    @foreach($verifyModes as $modeValue => $modeLabel)
                                        <option value="{{ $modeValue }}"
                                                @selected(old($verifyModeKey, $settings[$verifyModeKey] ?? 'automatic') === $modeValue)>
                                            {{ $modeLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                </div>

                @php
                    $identityPriceFields = [
                        'price_nin_verify' => 'NIN Verify Price',
                        'price_nin_slip_long' => 'NIN Slip Long Price',
                        'price_nin_slip_standard' => 'NIN Slip Standard Price',
                        'price_nin_slip_premium' => 'NIN Slip Premium Price',
                        'price_nin_slip_vnin' => 'NIN VNIN Slip Price',
                        'price_nin_validation_no_record' => 'NIN Validation (No Record)',
                        'price_nin_validation_update_record' => 'NIN Validation (Update Record)',
                        'price_bvn_verify' => 'BVN Verify Price',
                        'price_bvn_retrieve_phone' => 'BVN Retrieve by Phone Price',
                        'price_bvn_retrieve_bms' => 'BVN Retrieve by BMS Price',
                    ];
                @endphp

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                    @foreach($identityPriceFields as $priceKey => $priceLabel)
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">{{ $priceLabel }}</label>
                            <input type="number" step="0.01" name="{{ $priceKey }}"
                                   value="{{ old($priceKey, number_format(identity_price($priceKey), 2, '.', '')) }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            @php
                                $cost = identity_cost($priceKey);
                            @endphp
                            <div class="text-xs text-gray-500 mt-1">
                                {{ $cost === null ? 'No published provider rate for this job.' : 'Provider cost: ₦'.number_format($cost, 2) }}
                            </div>
                        </div>
                    @endforeach
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Base URL</label>
                        <input name="nin_base_url" value="{{ old('nin_base_url', $settings['nin_base_url'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN API Key</label>
                        <input name="nin_api_key" value="{{ old('nin_api_key', $settings['nin_api_key'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Print Endpoint</label>
                        <input name="nin_print_endpoint" value="{{ old('nin_print_endpoint', $settings['nin_print_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Reports Endpoint</label>
                        <input name="nin_reports_endpoint" value="{{ old('nin_reports_endpoint', $settings['nin_reports_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">NIN Validation Endpoint</label>
                        <input name="nin_validation_endpoint" value="{{ old('nin_validation_endpoint', $settings['nin_validation_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Base URL</label>
                        <input name="bvn_base_url" value="{{ old('bvn_base_url', $settings['bvn_base_url'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN API Key</label>
                        <input name="bvn_api_key" value="{{ old('bvn_api_key', $settings['bvn_api_key'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Verify Endpoint</label>
                        <input name="bvn_verify_endpoint" value="{{ old('bvn_verify_endpoint', $settings['bvn_verify_endpoint'] ?? '/bvn_search') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Retrieve Phone Endpoint</label>
                        <input name="bvn_retrieve_phone_endpoint" value="{{ old('bvn_retrieve_phone_endpoint', $settings['bvn_retrieve_phone_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Retrieve BMS Endpoint</label>
                        <input name="bvn_retrieve_bms_endpoint" value="{{ old('bvn_retrieve_bms_endpoint', $settings['bvn_retrieve_bms_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">BVN Print Endpoint</label>
                        <input name="bvn_print_endpoint" value="{{ old('bvn_print_endpoint', $settings['bvn_print_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>
            </div>

            <div id="group-manual-identity" class="scroll-mt-44">
                @php
                    // These requests have no provider behind them, so the price and
                    // the promise the customer sees are decided here alone.
                    $manualServices = app(\App\Services\ManualFulfilmentService::class);
                @endphp
                <div class="text-lg font-extrabold">Manual Identity Requests</div>
                <div class="text-xs text-gray-500 mt-1">
                    Services with no API connection. The customer pays and an admin completes the work,
                    so the price and the promised turnaround below are what the customer is shown.
                    Leave a field empty to use the built-in default.
                    NIN and BVN verification appear here too while they are switched to manual above.
                </div>

                <div class="mt-3 space-y-3">
                    @foreach($manualServices->catalogue() as $slug => $manual)
                        <div class="rounded-2xl border border-gray-200 bg-white p-4">
                            <div class="font-extrabold text-gray-900">{{ $manual['icon'] }} {{ $manual['title'] }}</div>
                            <div class="text-xs text-gray-500 mt-1">{{ $manual['summary'] }}</div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                                @if($manualServices->sharesWiredPrice($slug))
                                    <div class="sm:col-span-2">
                                        <label class="text-sm font-bold text-gray-800/80">Price (₦)</label>
                                        <div class="mt-1 px-4 py-3 rounded-2xl bg-gray-50 border border-gray-200 text-sm text-gray-700">
                                            ₦{{ number_format($manualServices->priceNaira($slug), 2) }} —
                                            set with the automatic version under NIN & BVN Services above, so
                                            switching modes never changes what the customer pays.
                                        </div>
                                    </div>
                                @else
                                    <div>
                                        <label class="text-sm font-bold text-gray-800/80">Price (₦)</label>
                                        <input type="number" step="0.01" min="0"
                                               name="{{ $manualServices->priceKey($slug) }}"
                                               value="{{ old($manualServices->priceKey($slug), $settings[$manualServices->priceKey($slug)] ?? '') }}"
                                               placeholder="{{ number_format($manualServices->defaultPrice($slug), 2) }}"
                                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                        @php
                                            $manualCost = $manualServices->providerCost($slug);
                                        @endphp
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ $manualCost === null ? 'No published provider rate for this job.' : 'Provider cost: ₦'.number_format($manualCost, 2) }}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-sm font-bold text-gray-800/80">Markup (₦)</label>
                                        <input type="number" step="0.01" min="0"
                                               name="markup_manual_{{ $slug }}"
                                               value="{{ old('markup_manual_'.$slug, $settings['markup_manual_'.$slug] ?? '') }}"
                                               placeholder="0"
                                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                    </div>
                                @endif
                                <div>
                                    <label class="text-sm font-bold text-gray-800/80">Turnaround (hours)</label>
                                    <input type="number" step="1" min="1"
                                           name="{{ $manualServices->turnaroundKey($slug) }}"
                                           value="{{ old($manualServices->turnaroundKey($slug), $settings[$manualServices->turnaroundKey($slug)] ?? '') }}"
                                           placeholder="Default {{ $manual['turnaround_default'] }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div id="group-sms-gateway" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Bulk SMS Gateway</div>
                <div class="text-xs text-gray-500 mt-1">
                    Fill these four in and the Broadcast page starts sending texts on its own.
                    Until then it only exports the recipient list. Leave a field empty to keep the value shown.
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">API Endpoint</label>
                        <input name="sms_endpoint" value="{{ old('sms_endpoint', $settings['sms_endpoint'] ?? '') }}" placeholder="https://api.provider.com/sms/send" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">API Key</label>
                        <input name="sms_api_key" value="{{ old('sms_api_key', $settings['sms_api_key'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Sender ID</label>
                        <input name="sms_sender_id" value="{{ old('sms_sender_id', $settings['sms_sender_id'] ?? '') }}" placeholder="BELIEVED" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Label shown on the Broadcast page</label>
                        <input name="sms_driver" value="{{ old('sms_driver', $settings['sms_driver'] ?? '') }}" placeholder="e.g. Termii" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>

                <details class="mt-4">
                    <summary class="text-sm font-bold text-gray-800/80 cursor-pointer">Provider-specific options</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">Auth header</label>
                            <input name="sms_auth_header" value="{{ old('sms_auth_header', $settings['sms_auth_header'] ?? 'Authorization') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        </div>
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">Body format</label>
                            <select name="sms_body_format" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                @foreach(['json', 'form'] as $format)
                                    <option value="{{ $format }}" @selected(old('sms_body_format', $settings['sms_body_format'] ?? 'json') === $format)>{{ strtoupper($format) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-bold text-gray-800/80">Success field / value</label>
                            <div class="flex gap-2 mt-1">
                                <input name="sms_success_field" value="{{ old('sms_success_field', $settings['sms_success_field'] ?? '') }}" placeholder="code" class="w-1/2 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                                <input name="sms_success_value" value="{{ old('sms_success_value', $settings['sms_success_value'] ?? '') }}" placeholder="000" class="w-1/2 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            </div>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="text-sm font-bold text-gray-800/80">Field name map (JSON)</label>
                            <textarea name="sms_param_map" rows="2" placeholder='{"to":"destination","message":"sms","from":"sender"}' class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">{{ old('sms_param_map', $settings['sms_param_map'] ?? '') }}</textarea>
                            <div class="text-xs text-gray-500 mt-1">Extra fixed fields go in the same shape: {"sms": "Some message"}.</div>
                        </div>
                    </div>
                </details>
            </div>

            <div id="group-app-download" class="scroll-mt-44">
                <div class="text-lg font-extrabold">App Download</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Android App Download URL</label>
                        <input name="app_download_url" value="{{ old('app_download_url', $settings['app_download_url'] ?? '') }}" placeholder="https://yourdomain.com/app/app-release.apk" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Latest App Version</label>
                        <input name="app_latest_version" value="{{ old('app_latest_version', $settings['app_latest_version'] ?? '') }}" placeholder="e.g. 1.0.3" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>
            </div>

            <div id="group-recharge-card" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Recharge Card Printing</div>
                <div class="text-xs text-gray-500 mt-1">
                    Control available networks/values and service IDs for recharge card printing.
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Networks (one per line)</label>
                        <textarea name="recharge_card_networks" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="mtn|MTN&#10;airtel|Airtel&#10;glo|Glo&#10;etisalat|9mobile">{{ old('recharge_card_networks', $settings['recharge_card_networks'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Card Values</label>
                        <textarea name="recharge_card_values" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                                  placeholder="100,200,400,500,1000">{{ old('recharge_card_values', $settings['recharge_card_values'] ?? '') }}</textarea>
                        <div class="text-xs text-gray-500 mt-1">You can use comma or one-per-line values. Example: 100,200,500.</div>
                    </div>
                </div>
            </div>

            <div id="group-footer-social" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Footer Contact & Social Links</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Footer Phone</label>
                        <input name="footer_phone" value="{{ old('footer_phone', $settings['footer_phone'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Footer Email</label>
                        <input name="footer_email" value="{{ old('footer_email', $settings['footer_email'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-bold text-gray-800/80">Footer Support Text</label>
                        <input name="footer_support_text" value="{{ old('footer_support_text', $settings['footer_support_text'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900"
                               placeholder="e.g. WhatsApp available 24/7">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Facebook URL</label>
                        <input name="social_facebook_url" value="{{ old('social_facebook_url', $settings['social_facebook_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">X (Twitter) URL</label>
                        <input name="social_x_url" value="{{ old('social_x_url', $settings['social_x_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">Instagram URL</label>
                        <input name="social_instagram_url" value="{{ old('social_instagram_url', $settings['social_instagram_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">TikTok URL</label>
                        <input name="social_tiktok_url" value="{{ old('social_tiktok_url', $settings['social_tiktok_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">YouTube URL</label>
                        <input name="social_youtube_url" value="{{ old('social_youtube_url', $settings['social_youtube_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-800/80">WhatsApp URL</label>
                        <input name="social_whatsapp_url" value="{{ old('social_whatsapp_url', $settings['social_whatsapp_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                    </div>
                </div>
            </div>

            <div id="group-error-codes" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Transaction Error Codes</div>
                <div class="rounded-2xl border border-gray-200 overflow-x-auto mt-3">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left p-3">Code</th>
                            <th class="text-left p-3">Meaning</th>
                            <th class="text-left p-3">User Message</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="border-t border-gray-200">
                            <td class="p-3 font-bold">#1</td>
                            <td class="p-3">User wallet is not enough for the purchase.</td>
                            <td class="p-3">FAILED! (#1) INSUFFICIENT BALANCE</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="p-3 font-bold">#2</td>
                            <td class="p-3">Provider-side insufficient balance / timeout / gateway issue.</td>
                            <td class="p-3">FAILED (#2): TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="p-3 font-bold">#3</td>
                            <td class="p-3">Provider rejected the request (invalid or failed request).</td>
                            <td class="p-3">FAILED (#3): PROVIDER REQUEST FAILED. TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="p-3 font-bold">#4</td>
                            <td class="p-3">Unexpected app/system error.</td>
                            <td class="p-3">FAILED (#4): SYSTEM ERROR. TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-gray-200">
                            <td class="p-3 font-bold">#5</td>
                            <td class="p-3">Invalid user phone number format.</td>
                            <td class="p-3">FAILED (#5): INCORRECT PHONE NUMBER</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-gray-200 pt-5">
                <div class="text-xs text-gray-500">
                    Review your changes, then save to apply them across the website.
                </div>
            </div>
            </div>
        </form>

        <form id="syncProviderPricesForm" method="POST" action="{{ route('admin.settings.provider-prices.sync') }}" class="hidden">
            @csrf
        </form>

    </div>

    <div class="page-save-overlay" role="region" aria-label="Save admin settings">
        <div class="page-save-overlay-card">
            <div class="page-save-overlay-copy">
                Save your admin setting changes anytime.
            </div>
            <button type="submit"
                    form="adminSettingsForm"
                    class="btn-primary page-save-overlay-button">
                Save Settings
            </button>
        </div>
    </div>

    <style>
        .page-save-overlay {
            position: fixed;
            right: 1.25rem;
            bottom: 1.25rem;
            z-index: 9999;
            width: min(360px, calc(100vw - 2rem));
            pointer-events: none;
        }

        .page-save-overlay::before {
            display: none;
        }

        .page-save-overlay-card {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border: 1px solid rgba(64, 87, 93, 0.12);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.94);
            padding: 0.75rem;
            box-shadow: 0 20px 44px rgba(17, 46, 110, 0.22);
            backdrop-filter: blur(14px);
            pointer-events: auto;
        }

        .page-save-overlay .btn-primary {
            width: auto;
            min-width: 138px;
            border-radius: 14px;
            padding-block: 0.78rem;
            box-shadow: 0 16px 32px rgba(28, 79, 161, 0.28);
        }

        @media (max-width: 640px) {
            .page-save-overlay {
                right: 0.85rem;
                bottom: calc(0.85rem + env(safe-area-inset-bottom));
                width: calc(100vw - 1.7rem);
            }

            .page-save-overlay-card {
                flex-direction: column;
                align-items: stretch;
            }

            .page-save-overlay .btn-primary {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <script>
        (function () {
            const saveOverlay = document.querySelector('.page-save-overlay');
            if (saveOverlay && saveOverlay.parentElement !== document.body) {
                document.body.appendChild(saveOverlay);
            }

            document.querySelectorAll('#adminSettingsForm input[type="file"]').forEach((input) => {
                const img = document.getElementById(input.id.replace(/Input$/, 'Preview'));
                const placeholder = document.getElementById(input.id.replace(/Input$/, 'Placeholder'));
                if (!img || !placeholder) return;

                input.addEventListener('change', () => {
                    const file = input.files?.[0];
                    if (!file || !file.type?.startsWith('image/')) return;

                    const reader = new FileReader();
                    reader.onload = () => {
                        img.src = reader.result;
                        img.classList.remove('hidden');
                        placeholder.classList.add('hidden');
                    };
                    reader.readAsDataURL(file);
                });
            });

        })();
    </script>

    <script>
        (function () {
            const search = document.getElementById('settingsSearch');
            const form = document.getElementById('adminSettingsForm');
            if (!search || !form) return;

            const clear = document.getElementById('settingsSearchClear');
            const counter = document.getElementById('settingsSearchCount');
            const empty = document.getElementById('settingsSearchEmpty');
            const chips = Array.from(document.querySelectorAll('#settingsSectionNav a[href^="#group-"]'));

            const depthOf = (el) => {
                let depth = 0;
                while ((el = el.parentElement)) depth++;
                return depth;
            };

            // One unit = one labelled setting. A setting can be wrapped by a bigger
            // unit (a table inside a panel), so parents are tracked and only hidden
            // once every setting inside them is hidden too.
            const sections = Array.from(form.querySelectorAll('[id^="group-"]'))
                .filter((group) => !group.classList.contains('hidden'))
                .map((group) => {
                    const heading = group.querySelector('.text-lg.font-extrabold, h2, h3');
                    const found = new Set();

                    group.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
                        const unit = field.closest('label') || field.closest('tr')
                            || field.closest('li') || field.closest('div') || field.parentElement;
                        if (unit && unit !== group) found.add(unit);
                    });

                    const units = Array.from(found).map((el) => ({
                        el,
                        text: [
                            el.textContent,
                            ...Array.from(el.querySelectorAll('input, select, textarea')).map((f) => f.name + ' ' + f.placeholder),
                            ...Array.from(el.querySelectorAll('option')).map((o) => o.textContent),
                        ].join(' ').toLowerCase().replace(/\s+/g, ' ').trim(),
                        children: [],
                        leaf: true,
                        show: true,
                        depth: depthOf(el),
                    }));

                    const byEl = new Map(units.map((unit) => [unit.el, unit]));
                    units.forEach((unit) => {
                        let parent = unit.el.parentElement;
                        while (parent && parent !== group) {
                            if (byEl.has(parent)) {
                                byEl.get(parent).children.push(unit);
                                byEl.get(parent).leaf = false;
                                break;
                            }
                            parent = parent.parentElement;
                        }
                    });

                    units.sort((a, b) => b.depth - a.depth);

                    return {
                        group,
                        units,
                        leafCount: units.filter((unit) => unit.leaf).length,
                        text: (heading ? heading.textContent + ' ' + group.id.replace('group-', '') : group.id).toLowerCase(),
                    };
                });

            const totalLeaves = sections.reduce((sum, section) => sum + section.leafCount, 0);

            function apply(rawQuery) {
                const query = rawQuery.trim().toLowerCase();

                if (query === '') {
                    sections.forEach((section) => {
                        section.group.style.removeProperty('display');
                        section.units.forEach((unit) => unit.el.style.removeProperty('display'));
                    });
                    chips.forEach((chip) => chip.style.removeProperty('display'));
                    counter.textContent = '';
                    empty.classList.add('hidden');
                    clear.classList.add('hidden');
                    clear.classList.remove('flex');
                    document.dispatchEvent(new CustomEvent('admin-settings-filtered'));
                    return;
                }

                let visible = 0;

                sections.forEach((section) => {
                    const sectionMatched = section.text.includes(query);

                    section.units.forEach((unit) => {
                        unit.show = sectionMatched
                            || unit.text.includes(query)
                            || unit.children.some((child) => child.show);
                        unit.el.style.display = unit.show ? '' : 'none';
                        if (unit.leaf && unit.show) visible++;
                    });

                    const shown = section.units.some((unit) => unit.show)
                        || (section.units.length === 0 && sectionMatched);
                    section.group.style.display = shown ? '' : 'none';

                    const chip = chips.find((link) => link.getAttribute('href') === '#' + section.group.id);
                    if (chip) chip.style.display = shown ? '' : 'none';
                });

                counter.textContent = visible + ' of ' + totalLeaves;
                empty.classList.toggle('hidden', visible !== 0);
                clear.classList.remove('hidden');
                clear.classList.add('flex');
                document.dispatchEvent(new CustomEvent('admin-settings-filtered'));
            }

            search.addEventListener('input', () => apply(search.value));
            clear.addEventListener('click', () => {
                search.value = '';
                apply('');
                search.focus();
            });
            search.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    search.value = '';
                    apply('');
                }
            });
        })();
    </script>
</x-app-layout>
