<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteEditorController extends Controller
{
    /**
     * @var array<int, string>
     */
    private array $editableKeys = [
        'editor_home_marquee_enabled',
        'editor_home_popup_enabled',
        'editor_dashboard_marquee_enabled',
        'editor_dashboard_popup_enabled',
        'editor_fund_wallet_marquee_enabled',
        'editor_whatsapp_button_enabled',
        'editor_dashboard_quick_access_enabled',
        'editor_dashboard_quick_tips_enabled',
        'editor_primary_color',
        'editor_secondary_color',
        'editor_surface_color',
        'editor_text_color',
        'editor_header_bg',
        'editor_header_text_color',
        'editor_enable_custom_css',
        'editor_custom_css',
        'editor_enable_custom_js',
        'editor_custom_js',
    ];

    public function index(): View
    {
        $settings = Setting::query()->pluck('value', 'key')->toArray();

        return view('admin.website-editor', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'editor_home_marquee_enabled' => ['nullable', 'in:0,1'],
            'editor_home_popup_enabled' => ['nullable', 'in:0,1'],
            'editor_dashboard_marquee_enabled' => ['nullable', 'in:0,1'],
            'editor_dashboard_popup_enabled' => ['nullable', 'in:0,1'],
            'editor_fund_wallet_marquee_enabled' => ['nullable', 'in:0,1'],
            'editor_whatsapp_button_enabled' => ['nullable', 'in:0,1'],
            'editor_dashboard_quick_access_enabled' => ['nullable', 'in:0,1'],
            'editor_dashboard_quick_tips_enabled' => ['nullable', 'in:0,1'],
            'editor_primary_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_secondary_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_surface_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_text_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_header_bg' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_header_text_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'editor_enable_custom_css' => ['nullable', 'in:0,1'],
            'editor_custom_css' => ['nullable', 'string', 'max:30000'],
            'editor_enable_custom_js' => ['nullable', 'in:0,1'],
            'editor_custom_js' => ['nullable', 'string', 'max:30000'],
        ]);

        $toggleKeys = [
            'editor_home_marquee_enabled',
            'editor_home_popup_enabled',
            'editor_dashboard_marquee_enabled',
            'editor_dashboard_popup_enabled',
            'editor_fund_wallet_marquee_enabled',
            'editor_whatsapp_button_enabled',
            'editor_dashboard_quick_access_enabled',
            'editor_dashboard_quick_tips_enabled',
            'editor_enable_custom_css',
            'editor_enable_custom_js',
        ];

        foreach ($toggleKeys as $key) {
            $data[$key] = $request->boolean($key) ? '1' : '0';
        }

        foreach ($this->editableKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        settings_flush_cache();

        return back()->with('success', 'Website Editor changes applied successfully.');
    }

    public function reset(): RedirectResponse
    {
        Setting::query()
            ->whereIn('key', $this->editableKeys)
            ->delete();

        settings_flush_cache();

        return back()->with('success', 'Website Editor settings have been reset to default.');
    }
}
