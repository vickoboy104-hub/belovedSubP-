<x-app-layout>
    <div class="admin-light-page mx-auto max-w-5xl space-y-5">

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <h2 class="text-2xl font-extrabold">Admin Settings</h2>
            <p class="text-white/60 text-sm mt-1">
                Control your markup, exam prices, branding, and WhatsApp support without editing code.
            </p>
        </div>

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

        <form id="adminSettingsForm" method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data"
              class="rounded-3xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-6 pb-36 space-y-6">
            @csrf

            <div class="space-y-8">
                <div class="sticky top-24 z-20 -mx-1 px-1">
                    <div class="rounded-2xl border border-white/10 bg-[#0b1220]/90 backdrop-blur p-2">
                        <nav class="flex gap-1 overflow-x-auto whitespace-nowrap text-[11px] sm:text-xs">
                            <a href="#group-branding" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Branding</a>
                            <a href="#group-announcements" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Announcements</a>
                            <a href="#group-maintenance-overlay" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Maintenance Overlay</a>
                            <a href="#group-catalog" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Service Catalog</a>
                            <a href="#group-data-defaults" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Data Defaults</a>
                            <a href="#group-data-pricing" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Data Pricing</a>
                            <a href="#group-wallet" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Wallet</a>
                            <a href="#group-referral" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Referral</a>
                            <a href="#group-provider" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Provider</a>
                            <a href="#group-service-map" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Service IDs</a>
                            <a href="#group-markup" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Markup</a>
                            <a href="#group-recharge-card" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Recharge Cards</a>
                            <a href="#group-exam-prices" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Exam Prices</a>
                            <a href="#group-identity-services" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">NIN/BVN</a>
                            <a href="#group-app-download" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">App Download</a>
                            <a href="#group-footer-social" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Footer & Social</a>
                            <a href="#group-error-codes" class="px-2 py-2 text-center leading-tight rounded-lg hover:bg-white/10">Error Codes</a>
                        </nav>
                    </div>
                </div>

            {{-- Branding --}}
            <div id="group-branding" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Branding</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Site Name</label>
                        <input name="site_name" value="{{ old('site_name', $settings['site_name'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">WhatsApp Link</label>
                        <input name="whatsapp_link" value="{{ old('whatsapp_link', $settings['whatsapp_link'] ?? 'https://wa.me/2348165587119') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <div class="text-xs text-white/50 mt-1">Example: https://wa.me/2348165587119</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">WhatsApp Channel Link</label>
                        <input name="whatsapp_channel_link" value="{{ old('whatsapp_channel_link', $settings['whatsapp_channel_link'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <div class="text-xs text-white/50 mt-1">Example: https://whatsapp.com/channel/XXXXXXXXXXX</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Logo (PNG)</label>
                        <input type="file" name="logo" accept="image/*"
                               id="logoInput"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        @if(!empty($settings['logo_url']))
                            <div class="mt-2 text-xs text-white/60">Current: <span class="font-bold">{{ $settings['logo_url'] }}</span></div>
                        @endif
                        <div class="mt-3 flex items-center gap-3">
                            <div class="w-14 h-14 rounded-2xl bg-white/10 border border-white/10 overflow-hidden flex items-center justify-center">
                                <img id="logoPreview"
                                     src="{{ $settings['logo_url'] ?? '' }}"
                                     class="w-full h-full object-cover {{ empty($settings['logo_url']) ? 'hidden' : '' }}"
                                     alt="Logo preview">
                                <span id="logoPlaceholder" class="text-xs text-white/50 {{ empty($settings['logo_url']) ? '' : 'hidden' }}">No logo</span>
                            </div>
                            <div class="text-xs text-white/50">Preview</div>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Favicon (PNG/ICO)</label>
                        <input type="file" name="favicon" accept="image/*"
                               id="faviconInput"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        @if(!empty($settings['favicon_url']))
                            <div class="mt-2 text-xs text-white/60">Current: <span class="font-bold">{{ $settings['favicon_url'] }}</span></div>
                        @endif
                        <div class="mt-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/10 overflow-hidden flex items-center justify-center">
                                <img id="faviconPreview"
                                     src="{{ $settings['favicon_url'] ?? '' }}"
                                     class="w-full h-full object-cover {{ empty($settings['favicon_url']) ? 'hidden' : '' }}"
                                     alt="Favicon preview">
                                <span id="faviconPlaceholder" class="text-xs text-white/50 {{ empty($settings['favicon_url']) ? '' : 'hidden' }}">No icon</span>
                            </div>
                            <div class="text-xs text-white/50">Preview</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Announcements & Marquee --}}
            <div id="group-announcements" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Announcements & Marquee</div>
                <div class="grid grid-cols-1 gap-6 mt-3">
                    <div class="rounded-2xl border border-white/10 p-4">
                        <div>
                            <label class="text-sm font-bold text-white/80">Marquee Speed (seconds per loop)</label>
                            <input type="number" step="0.5" min="5" max="120" name="marquee_speed_seconds"
                                   value="{{ old('marquee_speed_seconds', $settings['marquee_speed_seconds'] ?? '30') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            <div class="text-xs text-white/50 mt-1">Higher values make the marquee move slower. This applies to all marquee areas.</div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Landing Page (Home)</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-white/80">Marquee Text</label>
                                <textarea name="home_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">{{ old('home_marquee_message', $settings['home_marquee_message'] ?? $settings['popup_message'] ?? 'Need NIN services? Click WhatsApp Support to chat with us instantly.') }}</textarea>
                            </div>

                            <div class="flex items-center gap-3">
                                <input id="home_popup_enabled" type="checkbox" name="home_popup_enabled" value="1"
                                       @checked(old('home_popup_enabled', $settings['home_popup_enabled'] ?? $settings['popup_enabled'] ?? '1') == '1')
                                       class="w-5 h-5 rounded border-white/20 bg-black/30">
                                <label for="home_popup_enabled" class="text-sm text-white/80 font-bold">Enable Popup</label>
                            </div>

                            <div>
                                <label class="text-sm font-bold text-white/80">Popup Message</label>
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

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Dashboard</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-white/80">Marquee Text</label>
                                <textarea name="dashboard_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">{{ old('dashboard_marquee_message', $settings['dashboard_marquee_message'] ?? 'Need NIN services? Click WhatsApp Support to chat with us instantly.') }}</textarea>
                            </div>

                            <div class="flex items-center gap-3">
                                <input id="dashboard_popup_enabled" type="checkbox" name="dashboard_popup_enabled" value="1"
                                       @checked(old('dashboard_popup_enabled', $settings['dashboard_popup_enabled'] ?? '1') == '1')
                                       class="w-5 h-5 rounded border-white/20 bg-black/30">
                                <label for="dashboard_popup_enabled" class="text-sm text-white/80 font-bold">Enable Popup</label>
                            </div>

                            <div>
                                <label class="text-sm font-bold text-white/80">Popup Message</label>
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

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Fund Wallet Page</div>
                        <div class="grid grid-cols-1 gap-4 mt-3">
                            <div>
                                <label class="text-sm font-bold text-white/80">Marquee Text</label>
                                <textarea name="fund_wallet_marquee_message" rows="2"
                                          class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">{{ old('fund_wallet_marquee_message', $settings['fund_wallet_marquee_message'] ?? 'Flutterwave tip: Use checkout for instant card or bank payment, or generate your virtual account and fund it by transfer.') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Maintenance Overlay --}}
            <div id="group-maintenance-overlay" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Maintenance Overlay</div>
                <div class="text-xs text-white/50 mt-1">
                    Disabled by default. When enabled, a countdown overlay is shown to users until the end time.
                </div>

                <div class="rounded-2xl border border-white/10 p-4 mt-3">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="col-span-2 lg:col-span-4 flex items-center gap-3">
                            <input id="maintenance_overlay_enabled" type="checkbox" name="maintenance_overlay_enabled" value="1"
                                   @checked(old('maintenance_overlay_enabled', $settings['maintenance_overlay_enabled'] ?? '0') == '1')
                                   class="w-5 h-5 rounded border-white/20 bg-black/30">
                            <label for="maintenance_overlay_enabled" class="text-sm text-white/80 font-bold">
                                Enable Maintenance Overlay
                            </label>
                        </div>

                        <div>
                            <label class="text-sm font-bold text-white/80">End Time</label>
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
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            <div class="text-xs text-white/50 mt-1">Set to current time plus your preferred minutes.</div>
                        </div>

                        <div>
                            <label class="text-sm font-bold text-white/80">Overlay Message</label>
                            <textarea name="maintenance_overlay_message" rows="3"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                      placeholder="We are currently running an update. Please hold on while we finish.">{{ old('maintenance_overlay_message', $settings['maintenance_overlay_message'] ?? 'We are currently running an update. Please hold on while we finish.') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Service Catalog --}}
            <div id="group-catalog" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Service Catalog (Advanced)</div>
                <div class="text-xs text-white/50 mt-1">Format: one per line as <code>service_id|Display Name</code>. Leave blank to use defaults.</div>

                <div class="grid grid-cols-1 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Airtime Services</label>
                        <textarea name="services_airtime" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="mtn|MTN Airtime&#10;airtel|Airtel Airtime&#10;glo|GLO Airtime&#10;etisalat|9mobile Airtime">{{ old('services_airtime', $settings['services_airtime'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Data Services</label>
                        <textarea name="services_data" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="mtn_gifting|MTN Data (Gifting)&#10;mtn_awoof|MTN Awoof Data (Cheap)&#10;airtel_sme|Airtel Data (SME)&#10;glo_data|Glo Data&#10;etisalat_data|9mobile Data">{{ old('services_data', $settings['services_data'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Cable Services</label>
                        <textarea name="services_cable" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="dstv|DSTV Subscription&#10;gotv|GOTV Subscription&#10;startimes|Startimes Subscription">{{ old('services_cable', $settings['services_cable'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Electricity Services</label>
                        <textarea name="services_electricity" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="ikeja-electric|Ikeja Electric (IKEDC)&#10;eko-electric|Eko Electric (EKEDC)&#10;abuja-electric|Abuja Electric (AEDC)">{{ old('services_electricity', $settings['services_electricity'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Education Services</label>
                        <textarea name="services_education" rows="4"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="jamb|JAMB PIN (UTME & Direct Entry)&#10;waec|WAEC Result Checker PIN&#10;neco|NECO Result Checker PIN&#10;nabteb|NABTEB Result Checker PIN">{{ old('services_education', $settings['services_education'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Premium App Services</label>
                        <textarea name="services_premium" rows="3"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="canva|Canva Pro">{{ old('services_premium', $settings['services_premium'] ?? '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Data Defaults --}}
            <div id="group-data-defaults" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Data Defaults</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">MTN Quick Pick Default</label>
                        <select name="data_default_mtn_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
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
                        <div class="text-xs text-white/50 mt-1">Controls the MTN quick pick button on the data page.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Airtel Quick Pick Default</label>
                        <select name="data_default_airtel_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
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
                        <div class="text-xs text-white/50 mt-1">Controls the Airtel quick pick button on the data page.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Glo Quick Pick Default</label>
                        <select name="data_default_glo_service"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
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
                        <div class="text-xs text-white/50 mt-1">Controls the Glo quick pick button on the data page.</div>
                    </div>

                    <div class="sm:col-span-2 rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Data Service On/Off</div>
                        <div class="text-xs text-white/50 mt-1">
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
                                <div class="rounded-2xl border border-white/10 p-4">
                                    <div class="font-bold text-white/80">{{ $networkLabel }}</div>
                                    <div class="mt-3 space-y-2">
                                        @foreach($networkServices as $slug => $meta)
                                            @php
                                                $key = 'data_service_enabled_' . $slug;
                                                $checked = old($key, $settings[$key] ?? $meta['default']) === '1';
                                            @endphp
                                            <label class="flex items-center justify-between gap-3 text-sm">
                                                <span class="text-white/80">{{ $meta['label'] }}</span>
                                                <input type="checkbox"
                                                       name="{{ $key }}"
                                                       value="1"
                                                       @checked($checked)
                                                       class="w-5 h-5 rounded border-white/20 bg-black/30">
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
                <div class="text-lg font-extrabold">Data Plan Selling Prices</div>
                <div class="text-xs text-white/50 mt-1">
                    Format: <code>service_id|plan_id|selling_price</code>. Leave a plan out to display the exact GSUBZ price.
                </div>

                <div class="grid grid-cols-1 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Plan Price Overrides</label>
                        <textarea name="data_plan_price_overrides" rows="8"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="mtn_awoof|452|250&#10;mtn_awoof|453|600&#10;mtn_sme|PLAN_CODE|1200">{{ old('data_plan_price_overrides', $settings['data_plan_price_overrides'] ?? '') }}</textarea>
                        <div class="text-xs text-white/50 mt-1">
                            Customers see and pay the selling price. Provider purchases still use the original GSUBZ price.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Wallet Funding --}}
            <div id="group-wallet" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Wallet Funding</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Flutterwave Funding Fee (₦)</label>
                        <input type="number" step="0.01" name="wallet_funding_fee"
                               value="{{ old('wallet_funding_fee', $settings['wallet_funding_fee'] ?? '50') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <div class="text-xs text-white/50 mt-1">This fee is deducted from every Flutterwave deposit.</div>
                    </div>
                </div>
            </div>

            {{-- Referral System --}}
            <div id="group-referral" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Referral System</div>
                <div class="text-xs text-white/50 mt-1">
                    Separate from user discount. Referrer earns commission when qualified referrals purchase services.
                </div>

                <div class="mt-3 rounded-2xl border border-white/10 p-4 space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <label for="referral_system_enabled" class="text-sm font-bold text-white/80">Enable Referral System</label>
                        <input id="referral_system_enabled" type="checkbox" name="referral_system_enabled" value="1"
                               @checked(old('referral_system_enabled', $settings['referral_system_enabled'] ?? '1') === '1')
                               class="w-5 h-5 rounded border-white/20 bg-black/30">
                    </div>

                    <div>
                        <label class="text-sm font-bold text-white/80">Default Percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="referral_default_percent"
                               value="{{ old('referral_default_percent', $settings['referral_default_percent'] ?? '1') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <div class="text-xs text-white/50 mt-1">Used if a specific service percentage is not set.</div>
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
                            <div class="rounded-2xl border border-white/10 p-3 space-y-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-bold text-white/80">{{ $label }}</div>
                                    <input type="checkbox"
                                           name="{{ $enabledKey }}"
                                           value="1"
                                           @checked(old($enabledKey, $settings[$enabledKey] ?? '1') === '1')
                                           class="w-5 h-5 rounded border-white/20 bg-black/30">
                                </div>
                                <div>
                                    <label class="text-xs text-white/60">Percentage (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="{{ $percentKey }}"
                                           value="{{ old($percentKey, $settings[$percentKey] ?? '1') }}"
                                           class="w-full mt-1 px-3 py-2 rounded-xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Provider --}}
            <div id="group-provider" class="scroll-mt-44">
                <div class="text-lg font-extrabold">API Provider</div>
                <div class="text-xs text-white/50 mt-1">
                    Switch active provider and keep separate credentials and service-ID maps for each provider profile.
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Active Provider</label>
                        <select name="provider"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            @php $provider = old('provider', $settings['provider'] ?? 'gsubz'); @endphp
                            <option value="gsubz" @selected($provider === 'gsubz')>GSUBZ (Live)</option>
                            <option value="alt" @selected($provider === 'alt')>Alternative API</option>
                            <option value="mock" @selected($provider === 'mock')>Mock (Test Mode)</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">GSUBZ Credentials</div>
                        <div class="mt-3 space-y-3">
                            <div>
                                <label class="text-xs text-white/60">GSUBZ Base URL</label>
                                <input name="provider_gsubz_base_url"
                                       value="{{ old('provider_gsubz_base_url', $settings['provider_gsubz_base_url'] ?? 'https://api.gsubz.com') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            </div>
                            <div>
                                <label class="text-xs text-white/60">GSUBZ API Key</label>
                                <input name="provider_gsubz_api_key"
                                       value="{{ old('provider_gsubz_api_key', $settings['provider_gsubz_api_key'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Alternative Provider Credentials</div>
                        <div class="mt-3 space-y-3">
                            <div>
                                <label class="text-xs text-white/60">Alternative Base URL</label>
                                <input name="provider_alt_base_url"
                                       value="{{ old('provider_alt_base_url', $settings['provider_alt_base_url'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                       placeholder="https://api.example.com">
                            </div>
                            <div>
                                <label class="text-xs text-white/60">Alternative API Key</label>
                                <input name="provider_alt_api_key"
                                       value="{{ old('provider_alt_api_key', $settings['provider_alt_api_key'] ?? '') }}"
                                       class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-white/10 p-4">
                    <div class="font-bold text-white/80">NIN API Credentials</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                        <div>
                            <label class="text-xs text-white/60">NIN Base URL</label>
                            <input name="nin_base_url"
                                   value="{{ old('nin_base_url', $settings['nin_base_url'] ?? 'https://confirmident.com.ng/api') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        </div>
                        <div>
                            <label class="text-xs text-white/60">NIN API Key</label>
                            <input name="nin_api_key"
                                   value="{{ old('nin_api_key', $settings['nin_api_key'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        </div>
                        <div>
                            <label class="text-xs text-white/60">NIN Print Endpoint (Optional)</label>
                            <input name="nin_print_endpoint"
                                   value="{{ old('nin_print_endpoint', $settings['nin_print_endpoint'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                   placeholder="/Verify or full URL">
                        </div>
                        <div>
                            <label class="text-xs text-white/60">NIN Reports Endpoint (Optional)</label>
                            <input name="nin_reports_endpoint"
                                   value="{{ old('nin_reports_endpoint', $settings['nin_reports_endpoint'] ?? '') }}"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                   placeholder="/Verify/load or full URL">
                        </div>
                    </div>
                    <div class="text-xs text-white/50 mt-2">
                        Verification works with documented endpoints. Slip print and reports require provider endpoints from JHTech.
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-white/10 p-4">
                    <div class="font-bold text-white/80">Per-Provider Service Map Profiles</div>
                    <div class="text-xs text-white/50 mt-1">
                        Format: one per line as <code>service_slug|provider_service_id</code>. These override single-field mappings.
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-3">
                        <div>
                            <label class="text-xs text-white/60">GSUBZ Map Profile</label>
                            <textarea name="service_map_profile_gsubz" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                      placeholder="mtn_gifting|mtn_gifting&#10;jamb|jamb">{{ old('service_map_profile_gsubz', $settings['service_map_profile_gsubz'] ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs text-white/60">Alternative Map Profile</label>
                            <textarea name="service_map_profile_alt" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                      placeholder="mtn_gifting|provider_mtn_data">{{ old('service_map_profile_alt', $settings['service_map_profile_alt'] ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs text-white/60">Mock Map Profile</label>
                            <textarea name="service_map_profile_mock" rows="6"
                                      class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                      placeholder="jamb|mock_jamb">{{ old('service_map_profile_mock', $settings['service_map_profile_mock'] ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Service ID Overrides --}}
            <div id="group-service-map" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Service ID Overrides</div>
                <div class="text-xs text-white/50 mt-1">Leave blank to use the default service ID in code. These fields are provider service IDs, not plan prices.</div>

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
                        'jos-electic' => 'Jos Electric (JED)',
                        'kaduna-electric' => 'Kaduna Electric (KAEDCO)',
                        'kano-electric' => 'Kano Electric (KEDCO)',
                        'portharcourt-electric' => 'Port Harcourt Electric (PHED)',
                        'aba-electric' => 'Aba Electric (ABA)',
                        'yola-electric' => 'Yola Electric (YEDC)',
                        'benin-electric' => 'Benin Electric (BEDC)',
                        'enugu-electric' => 'Enugu Electric (EEDC)',
                    ];
                    $digitalMap = [
                        'canva' => 'Canva Pro',
                    ];
                @endphp

                <div class="mt-4 space-y-5">
                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Airtime</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($airtimeMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Recharge Cards</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($rechargeCardMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ str_replace('card_', '', $slug) }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Data</div>
                        <div class="text-xs text-white/50 mt-1">For Awoof, keep this as <code>mtn_awoof</code> or blank. Set customer profit under Customer Markup.</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($dataMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Cable TV</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($cableMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Electricity</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($electricityMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Exam Pins</div>
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
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ str_replace('service_exam_', '', $key) }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 p-4">
                        <div class="font-bold text-white/80">Social & Premium</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            @foreach($digitalMap as $slug => $label)
                                @php $key = 'service_map_' . $slug; @endphp
                                <div>
                                    <label class="text-sm font-bold text-white/80">{{ $label }}</label>
                                    <input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"
                                           placeholder="{{ $slug }}"
                                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Markups --}}
            <div id="group-markup" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Customer Markup (₦)</div>
                <div class="text-xs text-white/50 mt-1">This is added to provider price. This becomes your profit.</div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Airtime Markup</label>
                        <input type="number" step="0.01" name="markup_airtime"
                               value="{{ old('markup_airtime', $settings['markup_airtime'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Data Markup (Legacy)</label>
                        <input type="number" step="0.01" name="markup_data"
                               value="{{ old('markup_data', $settings['markup_data'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <div class="text-xs text-white/50 mt-1">Use Data Plan Selling Prices for customer data prices.</div>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Cable Markup</label>
                        <input type="number" step="0.01" name="markup_cable"
                               value="{{ old('markup_cable', $settings['markup_cable'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Electricity Markup</label>
                        <input type="number" step="0.01" name="markup_electricity"
                               value="{{ old('markup_electricity', $settings['markup_electricity'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Exam Markup</label>
                        <input type="number" step="0.01" name="markup_exam"
                               value="{{ old('markup_exam', $settings['markup_exam'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Recharge Card Markup</label>
                        <input type="number" step="0.01" name="markup_recharge_card"
                               value="{{ old('markup_recharge_card', $settings['markup_recharge_card'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Premium Apps Markup</label>
                        <input type="number" step="0.01" name="markup_premium"
                               value="{{ old('markup_premium', $settings['markup_premium'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Services Markup</label>
                        <input type="number" step="0.01" name="markup_bvn"
                               value="{{ old('markup_bvn', $settings['markup_bvn'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Print Markup</label>
                        <input type="number" step="0.01" name="markup_nin_print"
                               value="{{ old('markup_nin_print', $settings['markup_nin_print'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Validation Markup</label>
                        <input type="number" step="0.01" name="markup_nin_validation"
                               value="{{ old('markup_nin_validation', $settings['markup_nin_validation'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Airtime Discount (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="airtime_discount_percent"
                               value="{{ old('airtime_discount_percent', $settings['airtime_discount_percent'] ?? '2') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                </div>
            </div>

            {{-- Exam Base Prices --}}
            <div id="group-exam-prices" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Exam Base Prices (₦)</div>
                <div class="text-xs text-white/50 mt-1">Set provider/base price here. Customer pays base + exam markup.</div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">JAMB Base</label>
                        <input type="number" step="0.01" name="price_exam_jamb"
                               value="{{ old('price_exam_jamb', $settings['price_exam_jamb'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">WAEC Base</label>
                        <input type="number" step="0.01" name="price_exam_waec"
                               value="{{ old('price_exam_waec', $settings['price_exam_waec'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NECO Base</label>
                        <input type="number" step="0.01" name="price_exam_neco"
                               value="{{ old('price_exam_neco', $settings['price_exam_neco'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NABTEB Base</label>
                        <input type="number" step="0.01" name="price_exam_nabteb"
                               value="{{ old('price_exam_nabteb', $settings['price_exam_nabteb'] ?? '0') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Education Transaction Charge</label>
                        <input type="number" step="0.01" name="price_exam_transaction_fee"
                               value="{{ old('price_exam_transaction_fee', $settings['price_exam_transaction_fee'] ?? '100') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                </div>
            </div>

            <div id="group-identity-services" class="scroll-mt-44">
                <div class="text-lg font-extrabold">NIN & BVN Services</div>
                <div class="text-xs text-white/50 mt-1">Set pricing and endpoints. Endpoints can be relative (`/path`) or full URL.</div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Verify Price</label>
                        <input type="number" step="0.01" name="price_nin_verify" value="{{ old('price_nin_verify', $settings['price_nin_verify'] ?? '250') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Slip Long Price</label>
                        <input type="number" step="0.01" name="price_nin_slip_long" value="{{ old('price_nin_slip_long', $settings['price_nin_slip_long'] ?? '300') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Slip Standard Price</label>
                        <input type="number" step="0.01" name="price_nin_slip_standard" value="{{ old('price_nin_slip_standard', $settings['price_nin_slip_standard'] ?? '350') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Slip Premium Price</label>
                        <input type="number" step="0.01" name="price_nin_slip_premium" value="{{ old('price_nin_slip_premium', $settings['price_nin_slip_premium'] ?? '400') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN VNIN Slip Price</label>
                        <input type="number" step="0.01" name="price_nin_slip_vnin" value="{{ old('price_nin_slip_vnin', $settings['price_nin_slip_vnin'] ?? '180') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Validation (No Record)</label>
                        <input type="number" step="0.01" name="price_nin_validation_no_record" value="{{ old('price_nin_validation_no_record', $settings['price_nin_validation_no_record'] ?? '1000') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Validation (Update Record)</label>
                        <input type="number" step="0.01" name="price_nin_validation_update_record" value="{{ old('price_nin_validation_update_record', $settings['price_nin_validation_update_record'] ?? '1500') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Verify Price</label>
                        <input type="number" step="0.01" name="price_bvn_verify" value="{{ old('price_bvn_verify', $settings['price_bvn_verify'] ?? '100') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Retrieve by Phone Price</label>
                        <input type="number" step="0.01" name="price_bvn_retrieve_phone" value="{{ old('price_bvn_retrieve_phone', $settings['price_bvn_retrieve_phone'] ?? '2500') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Retrieve by BMS Price</label>
                        <input type="number" step="0.01" name="price_bvn_retrieve_bms" value="{{ old('price_bvn_retrieve_bms', $settings['price_bvn_retrieve_bms'] ?? '1000') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Base URL</label>
                        <input name="nin_base_url" value="{{ old('nin_base_url', $settings['nin_base_url'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN API Key</label>
                        <input name="nin_api_key" value="{{ old('nin_api_key', $settings['nin_api_key'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Print Endpoint</label>
                        <input name="nin_print_endpoint" value="{{ old('nin_print_endpoint', $settings['nin_print_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Reports Endpoint</label>
                        <input name="nin_reports_endpoint" value="{{ old('nin_reports_endpoint', $settings['nin_reports_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">NIN Validation Endpoint</label>
                        <input name="nin_validation_endpoint" value="{{ old('nin_validation_endpoint', $settings['nin_validation_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Base URL</label>
                        <input name="bvn_base_url" value="{{ old('bvn_base_url', $settings['bvn_base_url'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN API Key</label>
                        <input name="bvn_api_key" value="{{ old('bvn_api_key', $settings['bvn_api_key'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Verify Endpoint</label>
                        <input name="bvn_verify_endpoint" value="{{ old('bvn_verify_endpoint', $settings['bvn_verify_endpoint'] ?? '/bvn_search') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Retrieve Phone Endpoint</label>
                        <input name="bvn_retrieve_phone_endpoint" value="{{ old('bvn_retrieve_phone_endpoint', $settings['bvn_retrieve_phone_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Retrieve BMS Endpoint</label>
                        <input name="bvn_retrieve_bms_endpoint" value="{{ old('bvn_retrieve_bms_endpoint', $settings['bvn_retrieve_bms_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">BVN Print Endpoint</label>
                        <input name="bvn_print_endpoint" value="{{ old('bvn_print_endpoint', $settings['bvn_print_endpoint'] ?? '') }}" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                </div>
            </div>

            <div id="group-app-download" class="scroll-mt-44">
                <div class="text-lg font-extrabold">App Download</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Android App Download URL</label>
                        <input name="app_download_url" value="{{ old('app_download_url', $settings['app_download_url'] ?? '') }}" placeholder="https://yourdomain.com/app/app-release.apk" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Latest App Version</label>
                        <input name="app_latest_version" value="{{ old('app_latest_version', $settings['app_latest_version'] ?? '') }}" placeholder="e.g. 1.0.3" class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                </div>
            </div>

            <div id="group-recharge-card" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Recharge Card Printing</div>
                <div class="text-xs text-white/50 mt-1">
                    Control available networks/values and service IDs for recharge card printing.
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Networks (one per line)</label>
                        <textarea name="recharge_card_networks" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="mtn|MTN&#10;airtel|Airtel&#10;glo|Glo&#10;etisalat|9mobile">{{ old('recharge_card_networks', $settings['recharge_card_networks'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Card Values</label>
                        <textarea name="recharge_card_values" rows="5"
                                  class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                                  placeholder="100,200,400,500,1000">{{ old('recharge_card_values', $settings['recharge_card_values'] ?? '') }}</textarea>
                        <div class="text-xs text-white/50 mt-1">You can use comma or one-per-line values. Example: 100,200,500.</div>
                    </div>
                </div>
            </div>

            <div id="group-footer-social" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Footer Contact & Social Links</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="text-sm font-bold text-white/80">Footer Phone</label>
                        <input name="footer_phone" value="{{ old('footer_phone', $settings['footer_phone'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Footer Email</label>
                        <input name="footer_email" value="{{ old('footer_email', $settings['footer_email'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-bold text-white/80">Footer Support Text</label>
                        <input name="footer_support_text" value="{{ old('footer_support_text', $settings['footer_support_text'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white"
                               placeholder="e.g. WhatsApp available 24/7">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Facebook URL</label>
                        <input name="social_facebook_url" value="{{ old('social_facebook_url', $settings['social_facebook_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">X (Twitter) URL</label>
                        <input name="social_x_url" value="{{ old('social_x_url', $settings['social_x_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">Instagram URL</label>
                        <input name="social_instagram_url" value="{{ old('social_instagram_url', $settings['social_instagram_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">TikTok URL</label>
                        <input name="social_tiktok_url" value="{{ old('social_tiktok_url', $settings['social_tiktok_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">YouTube URL</label>
                        <input name="social_youtube_url" value="{{ old('social_youtube_url', $settings['social_youtube_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-white/80">WhatsApp URL</label>
                        <input name="social_whatsapp_url" value="{{ old('social_whatsapp_url', $settings['social_whatsapp_url'] ?? '') }}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                    </div>
                </div>
            </div>

            <div id="group-error-codes" class="scroll-mt-44">
                <div class="text-lg font-extrabold">Transaction Error Codes</div>
                <div class="rounded-2xl border border-white/10 overflow-x-auto mt-3">
                    <table class="w-full text-sm">
                        <thead class="bg-white/5">
                        <tr>
                            <th class="text-left p-3">Code</th>
                            <th class="text-left p-3">Meaning</th>
                            <th class="text-left p-3">User Message</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr class="border-t border-white/10">
                            <td class="p-3 font-bold">#1</td>
                            <td class="p-3">User wallet is not enough for the purchase.</td>
                            <td class="p-3">FAILED! (#1) INSUFFICIENT BALANCE</td>
                        </tr>
                        <tr class="border-t border-white/10">
                            <td class="p-3 font-bold">#2</td>
                            <td class="p-3">Provider-side insufficient balance / timeout / gateway issue.</td>
                            <td class="p-3">FAILED (#2): TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-white/10">
                            <td class="p-3 font-bold">#3</td>
                            <td class="p-3">Provider rejected the request (invalid or failed request).</td>
                            <td class="p-3">FAILED (#3): PROVIDER REQUEST FAILED. TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-white/10">
                            <td class="p-3 font-bold">#4</td>
                            <td class="p-3">Unexpected app/system error.</td>
                            <td class="p-3">FAILED (#4): SYSTEM ERROR. TRY AGAIN OR CONTACT BELOVEDSUBP</td>
                        </tr>
                        <tr class="border-t border-white/10">
                            <td class="p-3 font-bold">#5</td>
                            <td class="p-3">Invalid user phone number format.</td>
                            <td class="p-3">FAILED (#5): INCORRECT PHONE NUMBER</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-white/10 pt-5">
                <div class="text-xs text-white/50">
                    Review your changes, then save to apply them across the website.
                </div>
            </div>
            </div>
        </form>

    </div>

    <div class="page-save-overlay">
        <div class="page-save-overlay-card">
            <div class="page-save-overlay-copy">
                Save changes from anywhere on the page. This overlay stays visible while you review the admin settings.
            </div>
            <button type="submit"
                    form="adminSettingsForm"
                    class="btn-primary">
                Save Settings
            </button>
        </div>
    </div>

    <script>
        (function () {
            const logoInput = document.getElementById('logoInput');
            const faviconInput = document.getElementById('faviconInput');
            const logoPreview = document.getElementById('logoPreview');
            const faviconPreview = document.getElementById('faviconPreview');
            const logoPlaceholder = document.getElementById('logoPlaceholder');
            const faviconPlaceholder = document.getElementById('faviconPlaceholder');

            function updatePreview(input, img, placeholder) {
                const file = input?.files?.[0];
                if (!img || !placeholder) return;

                if (!file) return;
                if (!file.type || !file.type.startsWith('image/')) return;

                const reader = new FileReader();
                reader.onload = () => {
                    img.src = reader.result;
                    img.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }

            if (logoInput) {
                logoInput.addEventListener('change', () => updatePreview(logoInput, logoPreview, logoPlaceholder));
            }
            if (faviconInput) {
                faviconInput.addEventListener('change', () => updatePreview(faviconInput, faviconPreview, faviconPlaceholder));
            }

        })();
    </script>
</x-app-layout>
