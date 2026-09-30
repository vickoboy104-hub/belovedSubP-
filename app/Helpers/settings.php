<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

if (!function_exists('settings_flush_cache')) {
    function settings_flush_cache(): void
    {
        try {
            Cache::forget('settings.all');
        } catch (Throwable $e) {
            // Ignore cache flush issues to avoid breaking settings updates.
        }
    }
}

if (!function_exists('setting')) {
    function setting(string $key, $default = null)
    {
        static $settingsTableExists = null;

        if ($settingsTableExists === null) {
            try {
                $settingsTableExists = Schema::hasTable('settings');
            } catch (Throwable $e) {
                $settingsTableExists = false;
            }
        }

        if (!$settingsTableExists) {
            return $default;
        }

        try {
            $settings = Cache::rememberForever('settings.all', function () {
                return Setting::query()
                    ->pluck('value', 'key')
                    ->toArray();
            });
        } catch (Throwable $e) {
            try {
                $row = Setting::where('key', $key)->first();
                return $row ? $row->value : $default;
            } catch (Throwable $inner) {
                return $default;
            }
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }
}

if (!function_exists('site_name')) {
    // The admin can save a blank site name, which would otherwise leave pages
    // titled with the host's default rather than the brand.
    function site_name(): string
    {
        $stored = trim((string) setting('site_name'));
        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('app.name', 'BelovedSubP'));
    }
}

if (!function_exists('whatsapp_link')) {
    // Single source for the support number: Admin > Settings wins, and this
    // fallback is the only place the literal is written.
    function whatsapp_link(): string
    {
        $stored = trim((string) setting('whatsapp_link'));
        if ($stored !== '') {
            return $stored;
        }

        return 'https://wa.me/2347046246332';
    }
}

if (!function_exists('site_themes')) {
    // One registry for every skin the admin can pick. The key is what lands on
    // <html data-theme="...">, so CSS and this list must agree on the slug.
    function site_themes(): array
    {
        return [
            'navy' => [
                'name' => 'Classic Navy',
                'summary' => 'Deep blue, white and a dark orange accent. The look the site shipped with.',
                'swatches' => ['#112d57', '#173f74', '#ffffff', '#bd590e'],
            ],
            'ember' => [
                'name' => 'Ember Sunrise',
                'summary' => 'The same blue, but every plain blue surface mixes through into dark orange.',
                'swatches' => ['#1b3f74', '#2a5c9e', '#f4ece1', '#c25c0f'],
            ],
        ];
    }
}

if (!function_exists('site_theme')) {
    function site_theme(): string
    {
        $stored = trim((string) setting('site_theme'));

        return array_key_exists($stored, site_themes()) ? $stored : 'navy';
    }
}

if (!function_exists('logo_asset_url')) {
    // Settings store whatever the admin typed or uploaded, so every logo URL is
    // normalised the same way: absolute stays, relative gets the app origin.
    function logo_asset_url(string $key, string $fallbackKey = '', string $default = ''): string
    {
        $raw = trim((string) setting($key, setting($fallbackKey, '')));

        if ($raw === '') {
            return $default;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
            return $raw;
        }

        return asset($raw);
    }
}

if (!function_exists('site_logo_url')) {
    function site_logo_url(): string
    {
        return logo_asset_url('logo_url', 'site_logo', asset('images/logo.png'));
    }
}

if (!function_exists('site_login_logo_url')) {
    // Login, register and the public pages. Falls back to the site logo so an
    // admin who only uploaded one logo still gets it everywhere it belongs.
    function site_login_logo_url(): string
    {
        return logo_asset_url('login_logo_url') !== ''
            ? logo_asset_url('login_logo_url')
            : site_logo_url();
    }
}

if (!function_exists('site_loader_logo_url')) {
    // The boot splash and the page-transition loader. This deliberately never
    // falls back to the site logo: the loading mark is its own square asset.
    function site_loader_logo_url(): string
    {
        return logo_asset_url('loader_logo_url') !== ''
            ? logo_asset_url('loader_logo_url')
            : asset('images/logo-mark.webp');
    }
}

if (!function_exists('sanitize_popup_message_html')) {
    function sanitize_popup_message_html(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $allowedTags = ['p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'span', 'ul', 'ol', 'li'];

        try {
            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $root = $dom->documentElement;
            if (!$root instanceof \DOMElement) {
                return nl2br(e($html));
            }

            $sanitizeNode = function (\DOMNode $node) use (&$sanitizeNode, $allowedTags): void {
                if ($node->nodeType !== XML_ELEMENT_NODE) {
                    return;
                }

                $tag = strtolower($node->nodeName);
                if (!in_array($tag, $allowedTags, true)) {
                    $parent = $node->parentNode;
                    if ($parent) {
                        while ($node->firstChild) {
                            $parent->insertBefore($node->firstChild, $node);
                        }
                        $parent->removeChild($node);
                    }

                    return;
                }

                if ($node instanceof \DOMElement && $node->hasAttributes()) {
                    $attrs = [];
                    foreach ($node->attributes as $attribute) {
                        $attrs[] = $attribute->nodeName;
                    }

                    foreach ($attrs as $attributeName) {
                        if ($attributeName === 'style') {
                            $style = (string) $node->getAttribute('style');
                            if (preg_match('/text-align\s*:\s*(left|center|right)/i', $style, $match)) {
                                $node->setAttribute('style', 'text-align: '.strtolower($match[1]).';');
                            } else {
                                $node->removeAttribute('style');
                            }

                            continue;
                        }

                        if ($attributeName === 'align') {
                            $align = strtolower(trim((string) $node->getAttribute('align')));
                            if (in_array($align, ['left', 'center', 'right'], true)) {
                                $node->setAttribute('style', 'text-align: '.$align.';');
                            }

                            $node->removeAttribute('align');
                            continue;
                        }

                        $node->removeAttribute($attributeName);
                    }
                }

                $children = [];
                foreach ($node->childNodes as $child) {
                    $children[] = $child;
                }

                foreach ($children as $child) {
                    $sanitizeNode($child);
                }
            };

            $children = [];
            foreach ($root->childNodes as $child) {
                $children[] = $child;
            }

            foreach ($children as $child) {
                $sanitizeNode($child);
            }

            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }

            return trim($output);
        } catch (Throwable $e) {
            return nl2br(e($html));
        }
    }
}
