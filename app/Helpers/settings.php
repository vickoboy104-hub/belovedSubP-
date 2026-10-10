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

if (!function_exists('exam_price_defaults')) {
    /**
     * The only place an exam PIN price default is written. The buy form, the
     * purchase controller and Admin Settings all read this, because they used
     * to disagree and a customer would see one figure and be charged another.
     * These are starting prices for a fresh install, not the reseller's rate:
     * Admin Settings > Education overrides them.
     *
     * @return array<string, int>
     */
    function exam_price_defaults(): array
    {
        return [
            'jamb' => 7000,
            'waec' => 5500,
            'neco' => 5000,
            'nabteb' => 4500,
            'fee' => 100,
        ];
    }
}

if (!function_exists('jhtech_price_reference')) {
    /**
     * What each identity job costs this account, next to the price the site asks
     * a customer for. The two verification jobs are billed per call by
     * ConfirmIdent (N160 / N80, read from their dashboard and API documentation
     * on 2026-10-06); everything else has no API and is fulfilled by hand from
     * Admin > Manual requests at JH Tech's counter rates, read off their logged-in
     * service screens on 2026-10-07. A retail figure must stay above its cost or
     * the job is done at a loss.
     *
     * Keys are the setting keys Admin > Settings writes, so a price is only
     * ever spelled one way. A fresh install has no settings rows, which means
     * these retail numbers are what a customer is charged until the owner
     * overrides them.
     *
     * @return array<string, array{cost: int, retail: int}>
     */
    function jhtech_price_reference(): array
    {
        return [
            // The provider charges the same per verification whichever way the
            // record is found, but the three ways are not equally likely to come
            // back with a match, so each is priced separately here and the owner
            // raises the awkward ones without touching NIN-by-NIN.
            'price_nin_verify' => ['cost' => 160, 'retail' => 250],
            'price_nin_verify_by_phone' => ['cost' => 160, 'retail' => 250],
            'price_nin_verify_by_demo' => ['cost' => 160, 'retail' => 250],
            'price_nin_slip_long' => ['cost' => 180, 'retail' => 300],
            'price_nin_slip_standard' => ['cost' => 180, 'retail' => 350],
            'price_nin_slip_premium' => ['cost' => 180, 'retail' => 400],
            // Their own slip menu prices all three printed tiers at the same rate,
            // so the old 180 here was the cost, not a price.
            'price_nin_slip_vnin' => ['cost' => 180, 'retail' => 300],
            'price_nin_validation_no_record' => ['cost' => 700, 'retail' => 1000],
            'price_nin_validation_update_record' => ['cost' => 1000, 'retail' => 1500],
            'price_bvn_verify' => ['cost' => 80, 'retail' => 200],
            'price_bvn_retrieve_phone' => ['cost' => 2500, 'retail' => 3500],
            'price_bvn_retrieve_bms' => ['cost' => 1000, 'retail' => 1500],

            // Manually fulfilled identity jobs. Where one setting covers two JH
            // Tech jobs of different cost, the retail figure clears the dearer one.
            'price_manual_ipe_clearance' => ['cost' => 700, 'retail' => 3000],
            // Their personalization page asks 250 for the slip and 150 for the NIN
            // number alone, so the cheaper answer gets its own rate instead of
            // being sold at the slip price.
            'price_manual_nin_personalization' => ['cost' => 250, 'retail' => 3000],
            'price_manual_nin_personalization_nin_only' => ['cost' => 150, 'retail' => 2500],
            // Their counter charges one price per correction set, read straight off
            // the hidden fields on their own modification form: 5,000 for a single
            // detail, 6,000 when the name moves with phone, email or address,
            // 12,000 when a date of birth moves with a name or phone, and 33,000
            // for a date of birth on its own. Each tier below carries its own real
            // cost, so no correction is ever sold at the cheapest tier's rate.
            'price_manual_nin_modification' => ['cost' => 5000, 'retail' => 6500],
            'price_manual_nin_modification_name_pair' => ['cost' => 6000, 'retail' => 7500],
            'price_manual_nin_modification_dob_pair' => ['cost' => 12000, 'retail' => 15000],
            'price_manual_nin_modification_dob' => ['cost' => 33000, 'retail' => 40000],
            'price_manual_nin_delink' => ['cost' => 2000, 'retail' => 3000],
            'price_manual_nin_agreement' => ['cost' => 0, 'retail' => 2500],
            'price_manual_bvn_print' => ['cost' => 150, 'retail' => 1000],
            'price_manual_nin_slip_print' => ['cost' => 180, 'retail' => 1000],
            'price_manual_nin_validation' => ['cost' => 1000, 'retail' => 1500],
            'price_manual_bvn_retrieve' => ['cost' => 2500, 'retail' => 3500],
        ];
    }
}

if (!function_exists('identity_reference_price')) {
    /** The shipped default for an identity price key, ignoring anything saved. */
    function identity_reference_price(string $key, float $fallback = 0.0): float
    {
        $entry = jhtech_price_reference()[$key] ?? null;

        return $entry === null ? $fallback : (float) $entry['retail'];
    }
}

if (!function_exists('identity_price')) {
    /**
     * The price the site actually charges right now: whatever the owner saved in
     * Admin > Settings, otherwise the shipped default. Every page and every
     * purchase path must read prices through this, or an admin price change
     * silently stops reaching the customer.
     */
    function identity_price(string $key, float $fallback = 0.0): float
    {
        $stored = setting($key);

        if ($stored === null || $stored === '') {
            return identity_reference_price($key, $fallback);
        }

        return (float) $stored;
    }
}

if (!function_exists('identity_tier_price')) {
    /**
     * The price of one tier inside a service that has several.
     *
     * A tier is priced by its own setting when the owner has filled it in.
     * Otherwise the service-level setting still wins, so a price the owner set
     * before the service was split into tiers keeps applying to every tier
     * instead of being silently replaced by a built-in number. Only when neither
     * is saved do the built-in rates speak, and the tier's own rate is the one
     * that matches the job the customer selected.
     *
     * The provider's own charge is the floor: a tier can be raised to any margin
     * but never sold under what the same job costs us.
     */
    function identity_tier_price(string $tierKey, string $serviceKey): float
    {
        foreach ([$tierKey, $serviceKey] as $key) {
            $stored = setting($key);
            if ($stored !== null && $stored !== '') {
                return max((float) $stored, identity_cost($tierKey) ?? 0.0);
            }
        }

        return max(
            identity_reference_price($tierKey, identity_reference_price($serviceKey)),
            identity_cost($tierKey) ?? 0.0,
        );
    }
}

if (!function_exists('identity_cost')) {
    /** What the provider charges for the same job, or null when the rate is unknown. */
    function identity_cost(string $key): ?float
    {
        $entry = jhtech_price_reference()[$key] ?? null;
        if ($entry === null || (int) $entry['cost'] === 0) {
            return null;
        }

        return (float) $entry['cost'];
    }
}

if (!function_exists('identity_verify_mode')) {
    /**
     * How a verification job is run today: 'automatic' (the ConfirmIdent
     * endpoint answers on the spot) or 'manual' (an admin completes it from the
     * queue). The owner switches this in Admin > Settings per service.
     * Automatic is the default because that is the service the site sells; the
     * switch exists so a provider outage can be worked by hand without a deploy.
     */
    function identity_verify_mode(string $service): string
    {
        $stored = strtolower(trim((string) setting($service.'_verify_mode')));

        return $stored === 'manual' ? 'manual' : 'automatic';
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

if (!function_exists('popup_line_spacing')) {
    /**
     * The space between the lines of every message the admin writes into a popup.
     * It is one number for the whole site because the notices are the same box
     * everywhere, and it is clamped because a value typed wrong - 0, or 40 -
     * would either crush a warning into one unreadable line or push its own
     * buttons off the screen.
     */
    function popup_line_spacing(): string
    {
        $value = (float) setting('popup_line_spacing', '1.7');

        if (!is_finite($value)) {
            $value = 1.7;
        }

        return number_format(max(1.2, min(3.0, $value)), 2, '.', '');
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
