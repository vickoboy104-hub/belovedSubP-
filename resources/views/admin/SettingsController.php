<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function edit()
    {
        $settings = $this->allSettings();

        return view('admin.settings', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'site_name' => ['nullable', 'string', 'max:120'],
            'whatsapp_link' => ['nullable', 'string', 'max:255'],

            'popup_enabled' => ['nullable', 'in:1'],
            'popup_message' => ['nullable', 'string', 'max:500'],

            'markup_airtime' => ['nullable', 'numeric', 'min:0'],
            'markup_data' => ['nullable', 'numeric', 'min:0'],
            'markup_cable' => ['nullable', 'numeric', 'min:0'],
            'markup_electricity' => ['nullable', 'numeric', 'min:0'],
            'markup_exam' => ['nullable', 'numeric', 'min:0'],

            'price_exam_waec' => ['nullable', 'numeric', 'min:0'],
            'price_exam_neco' => ['nullable', 'numeric', 'min:0'],
            'price_exam_nabteb' => ['nullable', 'numeric', 'min:0'],

            'logo' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'image', 'max:1024'],
        ]);

        // checkbox normalization
        $data['popup_enabled'] = $request->has('popup_enabled') ? '1' : '0';

        // uploads
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('branding', 'public');
            $data['logo_url'] = Storage::disk('public')->url($path);
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('branding', 'public');
            $data['favicon_url'] = Storage::disk('public')->url($path);
        }

        // persist as key/value
        foreach ($data as $k => $v) {
            if ($k === 'logo' || $k === 'favicon') continue;
            $this->setSetting($k, is_null($v) ? '' : (string) $v);
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    private function allSettings(): array
    {
        // settings table must exist (migration below)
        try {
            return DB::table('settings')->pluck('value', 'key')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function setSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
