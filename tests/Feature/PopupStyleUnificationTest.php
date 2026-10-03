<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PopupStyleUnificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every page that can debit a wallet. Each one mounts the shared confirm
     * sheet, so they are the surfaces a leftover styling generation shows up on.
     *
     * @return array<int, string>
     */
    private function purchasePages(): array
    {
        return [
            '/vtu/airtime',
            '/vtu/data',
            '/vtu/electricity',
            '/vtu/cable',
            '/vtu/exam',
            '/vtu/premium-apps',
            '/vtu/recharge-card',
            '/vtu/nin-validation',
            '/vtu/nin',
        ];
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'first_name' => 'Test',
            'last_name' => 'Member',
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

    /**
     * The notifications primary key is a uuid that the framework's notification
     * pipeline assigns, so rows built through the relation need it set by hand.
     */
    private function notifyAbout(User $notifiable, array $data): void
    {
        $note = $notifiable->notifications()->make([
            'type' => 'test.notification',
            'data' => $data,
        ]);

        $note->id = (string) Str::uuid();
        $note->save();
    }

    /** Isolate the flash popup markup so assertions cannot pass off elsewhere. */
    private function flashBlock(string $html): string
    {
        $start = strpos($html, 'id="flashToast"');

        $this->assertNotFalse($start, 'No flash popup was rendered.');

        $block = substr($html, $start, 2600);
        $script = strpos($block, '<script');

        return $script === false ? $block : substr($block, 0, $script);
    }

    public function test_every_purchase_page_confirm_sheet_uses_the_shared_modal_skin(): void
    {
        $user = $this->member();

        foreach ($this->purchasePages() as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                'app-modal-overlay',
                $html,
                $path.' confirm sheet has no shared overlay.'
            );
            $this->assertStringContainsString(
                'app-modal-panel',
                $html,
                $path.' confirm sheet has no shared panel.'
            );
            $this->assertStringContainsString(
                'app-modal-btn-primary',
                $html,
                $path.' confirm sheet has no brand primary action.'
            );

            // The champagne-gold confirm button belonged to an older skin and is
            // not in either theme palette.
            $this->assertStringNotContainsString('#d8b07a', $html);
            $this->assertStringNotContainsString('#c99c60', $html);

            // int32-max sat above the loader the sheet itself triggers.
            $this->assertStringNotContainsString('2147483647', $html);
        }
    }

    public function test_a_server_flash_renders_the_same_popup_as_the_js_toast(): void
    {
        $html = $this->actingAs($this->member())
            ->withSession(['success' => 'Airtime delivered to 08031234567.'])
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $block = $this->flashBlock($html);

        $this->assertStringContainsString('app-modal-overlay', $block);
        $this->assertStringContainsString('app-modal-panel', $block);
        $this->assertStringContainsString('app-flag-tone-success', $block);

        // The retired dark-glass toast: frosted panel plus five classes that were
        // never defined anywhere in the stylesheet.
        $this->assertStringNotContainsString('bg-white/10', $block);
        $this->assertStringNotContainsString('toast-pop', $block);
        $this->assertStringNotContainsString('toast-icon', $block);
        $this->assertStringNotContainsString('toast-progress', $block);
    }

    public function test_a_failed_flash_gets_the_error_tone_not_a_green_face(): void
    {
        $html = $this->actingAs($this->member())
            ->withSession(['error' => 'Wallet balance is too low.'])
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $block = $this->flashBlock($html);

        $this->assertStringContainsString('app-flag-tone-error', $block);
        $this->assertStringNotContainsString('app-flag-tone-success', $block);
    }

    public function test_the_js_toast_and_the_blade_flash_agree_on_layer_and_markup(): void
    {
        $html = $this->actingAs($this->member())->get('/dashboard')->assertOk()->getContent();

        // Both paths build #flashToast, so they must declare one identical skin.
        $this->assertStringContainsString(
            "wrap.className = 'app-modal-overlay fixed inset-0 z-[99] flex items-center justify-center px-4'",
            $html
        );
        $this->assertStringContainsString('app-flag-tone-success', $html);
        $this->assertStringNotContainsString('border-emerald-200 bg-emerald-50 text-emerald-800', $html);
    }

    public function test_unread_items_in_the_member_inbox_use_the_shared_flag_language(): void
    {
        $user = $this->member();

        $this->notifyAbout($user, [
            'title' => 'Wallet funded',
            'message' => 'Your wallet received ₦1,000.',
        ]);

        $html = $this->actingAs($user)->get('/notifications')->assertOk()->getContent();

        $this->assertStringContainsString('app-note-card is-unread', $html);
        $this->assertStringContainsString('app-flag app-flag-unread', $html);
        $this->assertStringNotContainsString('bg-amber-100', $html);
        $this->assertStringNotContainsString('border-amber-200', $html);
    }

    public function test_admin_dashboard_cards_and_popups_drop_the_random_red_and_amber(): void
    {
        $admin = $this->admin();

        $this->notifyAbout($admin, [
            'title' => 'Provider outage',
            'message' => 'Data vendor is not responding.',
            'severity' => 'critical',
        ]);

        $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('app-note-card is-critical', $html);
        $this->assertStringContainsString('app-flag app-flag-critical', $html);

        // Shared danger action instead of bolting bg-rose-600 onto .btn-primary,
        // which the ember theme's !important gradient overwrote.
        $this->assertStringContainsString('app-modal-btn app-modal-btn-danger', $html);
        $this->assertStringContainsString('btn-danger', $html);
        $this->assertStringNotContainsString('btn-primary justify-center bg-rose-600', $html);
        $this->assertStringNotContainsString('bg-amber-50/70', $html);
    }

    public function test_destructive_admin_forms_use_the_shared_sheet_instead_of_the_browser_dialog(): void
    {
        $admin = $this->admin();

        foreach (['/admin/users', '/admin/website-editor'] as $path) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            // The sheet's own script mentions these attribute names, so scan markup only.
            $markup = preg_replace('/<script\b.*?<\/script>/s', '', $html);

            $this->assertStringNotContainsString('return confirm(', $markup, $path.' still uses the browser dialog.');

            // A gated form with no sheet on the page would swallow the click.
            preg_match_all('/data-confirm-sheet="([^"]+)"/', $markup, $sheets);

            $this->assertNotEmpty($sheets[1], $path.' gates no form at all.');

            foreach (array_unique($sheets[1]) as $sheet) {
                $this->assertStringContainsString(
                    'id="'.$sheet.'_overlay"',
                    $markup,
                    $path.' gates a form onto the missing sheet "'.$sheet.'".'
                );
            }
        }
    }

    public function test_the_recently_used_number_row_uses_the_shared_chip_skin(): void
    {
        $user = $this->member();
        $service = Service::create(['slug' => 'mtn', 'name' => 'MTN Airtime']);

        // Suggestions are derived from real past orders, so the chips only exist
        // when the account has bought with a valid phone number before.
        foreach ([
            ['meta' => ['type' => 'airtime'], 'customer_ref' => '08031234567'],
            ['meta' => ['type' => 'airtime'], 'customer_ref' => '08031234567'],
            ['meta' => ['type' => 'data'], 'customer_ref' => '08066789012'],
        ] as $order) {
            Order::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'customer_ref' => $order['customer_ref'],
                'amount' => 100000,
                'provider' => 'mock',
                'status' => 'success',
                'meta' => $order['meta'],
            ]);
        }

        foreach (['/vtu/airtime/mtn', '/vtu/data/mtn_sme'] as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                'app-choice-chip',
                $html,
                $path.' number row is not on the shared chip skin.'
            );
            $this->assertStringContainsString(
                'app-choice-caption',
                $html,
                $path.' number row has no caption.'
            );
            $this->assertStringContainsString('Recently used numbers', $html);

            // The old hand-pasted slate pill skin is retired.
            $this->assertStringNotContainsString(
                'phone-suggestion shrink-0 rounded-full border border-slate-200',
                $html,
                $path.' still renders the old slate number pills.'
            );
        }

        $airtime = $this->actingAs($user)->get('/vtu/airtime/mtn')->assertOk()->getContent();

        $this->assertStringContainsString('Quick amounts', $airtime);
        $this->assertStringNotContainsString(
            'airtime-amount-preset rounded-full border border-slate-200',
            $airtime,
            'The amount presets still render the old slate pills.'
        );
    }

    public function test_the_built_stylesheet_carries_the_popup_tokens_and_the_ember_skin(): void
    {
        $css = '';

        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= file_get_contents($file);
        }

        $this->assertNotSame('', $css, 'No compiled stylesheet was found.');

        // Panel and buttons resolve from tokens, so a popup cannot disagree with
        // the theme the page behind it is wearing.
        $this->assertStringContainsString('.app-modal-panel', $css);
        $this->assertStringContainsString('.app-modal-btn-primary', $css);
        $this->assertStringContainsString('.app-modal-btn-danger', $css);
        $this->assertStringContainsString('.app-note-card.is-unread', $css);
        $this->assertStringContainsString('.app-choice-chip', $css);
        $this->assertStringContainsString('.app-choice-caption', $css);
        $this->assertStringContainsString('data-theme=ember] .app-modal-panel', $css);

        $this->assertStringNotContainsString('d8b07a', $css);
        $this->assertStringNotContainsString('.toast-pop', $css);
    }
}
