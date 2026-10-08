<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ten things the owner could see from a phone and a desktop: where the alert
 * tray lands, what the sign-in card wears, which boxes a receipt prints, and
 * whether the shell actually moves when the menu is opened. Each one is a rendered
 * page plus the stylesheet the browser was handed, because a rule that never
 * survived the build fixes nothing.
 */
class InterfaceChromeTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'first_name' => 'Vicko',
            'last_name' => 'Owner',
        ]);

        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    /** The whole settings table is cached as one blob, so a write is invisible until it is dropped. */
    private function saveSetting(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        settings_flush_cache();
    }

    private function builtCss(): string
    {
        $css = '';

        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= file_get_contents($file);
        }

        $this->assertNotSame('', $css, 'No compiled stylesheet was found.');

        return $css;
    }

    private function builtJs(): string
    {
        $js = '';

        foreach (glob(public_path('build/assets/app-*.js')) ?: [] as $file) {
            $js .= file_get_contents($file);
        }

        $this->assertNotSame('', $js, 'No compiled script bundle was found.');

        return $js;
    }

    public function test_the_alert_tray_opens_in_a_layer_that_centres_it(): void
    {
        $html = $this->actingAs($this->member())->get('/dashboard')->assertOk()->getContent();

        // The tray is no longer pinned to the bell's corner, so a phone gets the
        // whole list instead of the part that happened to fit.
        $this->assertStringContainsString('class="app-bell-layer"', $html);
        $this->assertStringContainsString('app-glass-card app-bell-panel', $html);

        $css = $this->builtCss();
        $this->assertStringContainsString('.app-bell-layer', $css);
        $this->assertStringContainsString('.app-bell-panel', $css);
    }

    public function test_the_sign_in_card_wears_its_emblem_icons_and_notice(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // The mark sits over the top edge of the card rather than above it in the
        // page flow, which is what makes the card read as one object.
        $this->assertStringContainsString('reference-auth-crest', $html);
        $this->assertStringContainsString('reference-auth-notice', $html);
        $this->assertStringContainsString('aria-label="Dismiss this notice"', $html);

        // One icon inside each field, and the password can be read back.
        $this->assertSame(2, substr_count($html, 'class="app-field-icon"'));
        $this->assertStringContainsString('class="app-field-reveal"', $html);
        $this->assertStringContainsString("x-bind:type=\"revealed ? 'text' : 'password'\"", $html);

        // Alpine owns the type, so a static one would fight it and leave the
        // password unreadable whatever the eye button says.
        $this->assertStringNotContainsString('type="password"', $html);

        $css = $this->builtCss();

        foreach (['.reference-auth-crest', '.reference-auth-notice', '.app-field-icon', '.app-field-reveal'] as $selector) {
            $this->assertStringContainsString($selector, $css, $selector.' never reached the browser.');
        }

        // A field that refuses to shrink is what pushed the old forms to the left.
        $this->assertStringContainsString('.app-field :is(input,select):not([type=checkbox]){padding-left:2.5rem}', $css);
    }

    public function test_the_channel_notice_only_shows_where_the_admin_left_it(): void
    {
        $this->saveSetting('whatsapp_channel_link', 'https://whatsapp.example/channel');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('id="ninPopupOverlay"', $html);
        $this->assertStringContainsString('data-popup-key="login_popup_seen"', $html);
        $this->assertStringContainsString('Join our WhatsApp channel for giveaways', $html);
        $this->assertStringContainsString('Join our WhatsApp Channel', $html);

        // The switch is the admin's, and it is off for the sign-in page only.
        $this->saveSetting('login_popup_enabled', '0');
        $this->assertStringNotContainsString('ninPopupOverlay', $this->get('/login')->assertOk()->getContent());

        $this->saveSetting('login_popup_enabled', '1');
        $this->saveSetting('login_popup_message', 'Prizes drop every Friday night.');
        $this->assertStringContainsString('Prizes drop every Friday night.', $this->get('/login')->assertOk()->getContent());

        // No other public page inherits an invitation meant for the way in.
        $this->assertStringNotContainsString('login_popup_seen', $this->get('/register')->assertOk()->getContent());
    }

    public function test_the_receipt_stops_printing_the_provider_and_the_fee(): void
    {
        $user = $this->member();
        $service = Service::create(['slug' => 'mtn_sme', 'name' => 'MTN SME Data']);

        $order = Order::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'customer_ref' => '08031234567',
            'amount' => 100000,
            'provider' => 'gsubz',
            'status' => 'success',
            'meta' => [
                'type' => 'data',
                'service_id' => 'mtn_sme',
                'plan' => '5GB',
                'markup_naira' => 40.00,
            ],
        ]);

        $html = $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->getContent();

        // The margin is ours to keep private, and the vendor name means nothing
        // to the person who just paid.
        $this->assertStringNotContainsString('>Fee<', $html);
        $this->assertStringNotContainsString('>Provider<', $html);
        $this->assertStringNotContainsString('40.00', $html);

        // The one reference the customer can quote if something goes wrong.
        $this->assertStringContainsString('Provider Ref', $html);
    }

    public function test_the_admin_discount_button_is_legible_on_every_theme(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/users')->assertOk()->getContent();

        // It carried a raw colour instead of the shared button, so the label went
        // white-on-white the moment that one utility stopped being generated.
        $this->assertStringContainsString('Update %', $html);
        $this->assertStringContainsString('btn-primary justify-center px-3 py-2 text-xs', $html);
        $this->assertStringNotContainsString('bg-[#17233d] px-3 py-2 text-xs font-bold text-white', $html);
    }

    public function test_recent_numbers_wrap_and_the_phone_field_lets_go(): void
    {
        $user = $this->member();
        $service = Service::create(['slug' => 'mtn', 'name' => 'MTN Airtime']);

        foreach (['08031234567', '08031234567', '08066789012'] as $ref) {
            Order::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'customer_ref' => $ref,
                'amount' => 100000,
                'provider' => 'mock',
                'status' => 'success',
                'meta' => ['type' => 'airtime'],
            ]);
        }

        $html = $this->actingAs($user)->get('/vtu/airtime/mtn')->assertOk()->getContent();

        // Every saved number is on screen at once instead of sliding sideways out
        // of the card, and the field no longer forces the column wider than it is.
        $this->assertStringContainsString('class="app-phone-chip-row mt-2"', $html);
        $this->assertStringContainsString('<div class="min-w-0">', $html);
        $this->assertStringContainsString('data-contact-picker-button', $html);
        $this->assertStringContainsString('autocomplete="tel-national"', $html);
        $this->assertStringContainsString('08066789012', $html);
        $this->assertStringContainsString('class="app-phone-chip-count"', $html, 'A number used twice does not say so.');

        $css = $this->builtCss();

        foreach (['.app-phone-chip-row', '.app-phone-chip-count', '.contact-picker-row'] as $selector) {
            $this->assertStringContainsString($selector, $css, $selector.' never reached the browser.');
        }

        /* The contact button ships hidden and the script reveals it on a browser
           that can open the phone's address book. That reveal only works while the
           showing rule outranks the hiding one: `@apply hidden` compiles straight
           into the button's own selector, and an unlayered declaration beats the
           layered `.inline-flex` utility, so handing the element that utility left
           it invisible on every phone. */
        $this->assertStringContainsString('.contact-picker-btn.is-available{display:inline-flex}', $css);
        $this->assertStringContainsString('is-available', $this->builtJs(), 'The script no longer reveals the contact picker.');
    }

    public function test_the_shell_is_pushed_aside_for_the_drawer_instead_of_covering_it(): void
    {
        $html = $this->actingAs($this->member())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('reference-app-shell', $html);
        $this->assertStringContainsString("'mobile-nav-open': drawerOpen", $html);
        $this->assertStringContainsString("'desktop-nav-collapsed': !desktopNavOpen", $html);
        $this->assertStringContainsString('reference-drawer-scrim', $html);

        $css = $this->builtCss();

        // A transform on the shell would re-anchor every fixed layer in the app,
        // including the alert tray and the dialogs, so the push is done with
        // offsets and a margin only. These fragments are written the way the
        // bundler emits them, since it rewrites spacing and longhand functions.
        $this->assertStringContainsString('.reference-app-shell.mobile-nav-open .reference-main{margin-left:var(--drawer-w)}', $css);
        $this->assertStringContainsString('.reference-app-shell.mobile-nav-open .reference-header{left:var(--drawer-w)', $css);
        $this->assertStringContainsString('.reference-drawer-scrim{left:var(--drawer-w)', $css);

        // The desktop bar slides rather than blinking out of existence, and the
        // content underneath it moves with the slide.
        $this->assertStringContainsString('.reference-app-shell.desktop-nav-collapsed .reference-main{margin-left:0}', $css);
        $this->assertStringContainsString('#desktop-navigation{transform:translate(-100%)}', $css);
    }

    public function test_the_loader_keeps_turning_for_a_reader_who_hates_motion(): void
    {
        $css = $this->builtCss();

        // The owner's desktop asks for reduced motion, which used to freeze the
        // spinner mid-turn: the animation is slowed and the decoration dropped,
        // never removed, so a wait still looks like a wait.
        $this->assertStringContainsString('.reference-brand-ring{animation-duration:1.9s}', $css);
        $this->assertStringContainsString('.btn-loading:after{animation-duration:1.6s}', $css);
        $this->assertStringContainsString('animation:referenceBrandSpin 1s linear infinite', $css);
    }

    public function test_the_wallet_board_is_reachable_from_the_admin_menu(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('Wallet Statistics', $html);
        $this->assertStringContainsString(route('admin.wallet-stats'), $html);
    }
}
