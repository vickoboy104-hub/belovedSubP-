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
            '/vtu/airtime/mtn',
            '/vtu/data/mtn_sme',
            '/vtu/electricity/ikeja-electric',
            '/vtu/cable/dstv',
            '/vtu/exam/jamb',
            '/vtu/premium-apps',
            '/vtu/recharge-card',
            '/vtu/nin',
            '/vtu/nin-validation',
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

    /** Isolate the popup markup so assertions cannot pass off elsewhere. */
    private function sheetBlock(string $html, string $sheetId): string
    {
        $start = strpos($html, 'id="'.$sheetId.'_overlay"');

        $this->assertNotFalse($start, 'No sheet named '.$sheetId.' was rendered.');

        return substr($html, $start, 5000);
    }

    /**
     * The flash no longer prints a popup of its own; it hands the answer to the
     * layout's one dialog kernel. That handover is what the page must show.
     */
    private function pendingFlash(array $payload): array
    {
        $html = $this->actingAs($this->member())
            ->withSession($payload)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        preg_match('/window\.pendingFlashDialog = (\{.*?\});/s', $html, $match);

        $this->assertNotEmpty($match, 'The server flash never reached the dialog kernel.');

        $handed = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($handed, 'The flash handover is not readable JSON.');

        return [$html, $handed];
    }

    public function test_every_purchase_page_confirm_sheet_uses_the_shared_modal_skin(): void
    {
        $user = $this->member();

        foreach ($this->purchasePages() as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            // The sheet's own script mentions these attribute names, so scan markup only.
            $markup = preg_replace('/<script\b.*?<\/script>/s', '', $html);

            preg_match_all('/id="([^"]+)_overlay"/', $markup, $sheets);
            $this->assertNotEmpty($sheets[1], $path.' mounts no confirm sheet.');

            foreach (array_unique($sheets[1]) as $sheet) {
                $block = $this->sheetBlock($markup, $sheet);

                $this->assertStringContainsString('app-modal-overlay', $block, $path.' sheet has no shared overlay.');
                $this->assertStringContainsString('app-modal-panel', $block, $path.' sheet has no shared panel.');
                $this->assertStringContainsString('app-modal-btn-primary', $block, $path.' sheet has no brand primary action.');
                $this->assertStringContainsString('app-dialog-accent', $block, $path.' sheet has no tone bar.');
            }

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
        [$html, $handed] = $this->pendingFlash(['success' => 'Airtime delivered to 08031234567.']);

        $this->assertSame('success', $handed['type']);
        $this->assertSame('Airtime delivered to 08031234567.', $handed['message']);

        // One kernel builds the sheet, so a flash and a fetch answer cannot drift.
        $this->assertSame(1, substr_count($html, 'function showAppDialog('));
        $this->assertStringContainsString("wrap.className = 'app-modal-overlay fixed inset-0 z-[99] flex items-center justify-center px-4'", $html);
        $this->assertStringContainsString('window.showFlashToast = showFlashToast;', $html);

        // The old page-level copy of the popup is gone, along with the second
        // overlay the layout used to ship on every signed-in page.
        $this->assertStringNotContainsString('id="flashToast"', $html);
        $this->assertStringNotContainsString('transactionResultOverlay', $html);
        $this->assertStringNotContainsString('transactionContinueOverlay', $html);

        // The retired dark-glass toast: frosted panel plus classes that were never
        // defined anywhere in the stylesheet.
        $this->assertStringNotContainsString('bg-white/10', $html);
        $this->assertStringNotContainsString('toast-pop', $html);
        $this->assertStringNotContainsString('toast-icon', $html);
        $this->assertStringNotContainsString('toast-progress', $html);
    }

    public function test_a_failed_flash_gets_the_error_tone_not_a_green_face(): void
    {
        [$html, $handed] = $this->pendingFlash(['error' => 'Wallet balance is too low.']);

        $this->assertSame('error', $handed['type']);
        $this->assertSame('Wallet balance is too low.', $handed['message']);

        // The kernel decides the tone from that type, so a failure can only ever
        // be shown in the error skin.
        $this->assertStringContainsString("const tone = options.tone === 'error'", $html);
        $this->assertStringNotContainsString('border-emerald-200 bg-emerald-50 text-emerald-800', $html);
    }

    public function test_the_result_popup_stays_until_the_customer_answers_it(): void
    {
        $html = $this->actingAs($this->member())->get('/dashboard')->assertOk()->getContent();

        // A money result that vanishes from the screen leaves no proof of what
        // happened, so nothing in the kernel may dismiss a dialog on a timer.
        preg_match('/function showAppDialog\(.*?\n        \}/s', $html, $kernel);
        $this->assertNotEmpty($kernel, 'The dialog kernel is missing from the layout.');
        $this->assertStringNotContainsString('setTimeout', $kernel[0]);

        // A success that comes back with an order answers with the receipt, in one
        // sheet rather than the old chain of two.
        $this->assertStringContainsString("showTransactionResult", $html);
        $this->assertStringContainsString("{ label: 'View receipt', variant: 'primary', href: getReceiptUrl(orderId) }", $html);
    }

    public function test_the_dialog_kernel_arrives_at_the_browser_in_one_piece(): void
    {
        $html = $this->actingAs($this->member())->get('/dashboard')->assertOk()->getContent();

        // A Blade component tag written anywhere in this layout - including inside
        // a JS comment - is compiled into real markup. A </script> carried by that
        // markup ends the kernel early, and every popup on the site disappears
        // without a single test failing, so the exported tail is checked here.
        $start = strpos($html, 'function showAppDialog');

        $this->assertNotFalse($start, 'The dialog kernel is missing from the page.');

        $end = strpos($html, '</script>', $start);
        $kernel = substr($html, $start, $end - $start);

        $this->assertStringContainsString('window.showAppDialog = showAppDialog;', $kernel);
        $this->assertStringContainsString('window.notify = showFlashToast;', $kernel);
        $this->assertStringNotContainsString('<script', $kernel);
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

    public function test_no_page_asks_the_browser_for_a_confirmation_it_cannot_style(): void
    {
        // A browser dialog cannot be themed, cannot be trusted to be read, and on
        // some mobile browsers is suppressed entirely - which would silently
        // swallow the submit it was guarding.
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();

            $this->assertStringNotContainsString(
                'return confirm(',
                $source,
                $file->getRelativePathname().' still gates a submission on the browser dialog.'
            );
            $this->assertStringNotContainsString(
                'window.confirm(',
                $source,
                $file->getRelativePathname().' still gates a submission on the browser dialog.'
            );
        }
    }

    public function test_every_form_gated_on_a_sheet_ships_that_sheet_on_the_same_page(): void
    {
        // A gate with no sheet behind it swallows the button press in silence, and
        // nothing else in the suite notices because the form simply never submits.
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();

            preg_match_all('/data-confirm-sheet="([^"]+)"/', $source, $gates);

            foreach (array_unique($gates[1]) as $sheet) {
                $this->assertStringContainsString(
                    '<x-confirm-modal id="'.$sheet.'"',
                    $source,
                    $file->getRelativePathname().' gates a form onto the missing sheet "'.$sheet.'".'
                );
            }
        }
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

        // The dialog focuses its own go-button, so the ring it lands in has to be
        // the brand's, not the browser's black outline.
        $this->assertStringContainsString('.app-modal-btn:focus-visible', $css);
        $this->assertStringContainsString('data-theme=ember] .app-modal-panel', $css);

        $this->assertStringNotContainsString('d8b07a', $css);
        $this->assertStringNotContainsString('.toast-pop', $css);
    }
}
