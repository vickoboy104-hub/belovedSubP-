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
