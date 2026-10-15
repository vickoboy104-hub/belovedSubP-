<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AdminSystemAlertNotification;
use App\Notifications\ManualOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The whole point of the manual queue: a key-less identity service still takes
 * money, still shows the customer a receipt, and still returns a result.
 */
class ManualIdentityQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_customer_is_charged_and_the_request_lands_in_the_admin_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $response = $this->actingAs($user)
            ->post('/vtu/manual/ipe_clearance', [
                'ipe_type' => 'new_enrollment',
                'tracking_id' => 'ABCDEFGHIJKLMNO',
            ]);

        $order = $this->latestOrder($user);
        $response->assertRedirect(route('vtu.receipt', $order->id));

        $this->assertSame(200_000, $this->balance($user));
        $this->assertSame('pending', $order->status);
        $this->assertSame('manual', $order->provider);

        $this->actingAs($admin)->get('/admin/manual-orders')->assertOk()->assertSee('IPE Clearance');
    }

    public function test_the_admin_reads_every_answer_the_document_and_the_money(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(9_000_000);

        // The most demanding branch there is: a date of birth correction asks a
        // number, a document and an email choice, and is priced above the others.
        // Everything the customer answered has to arrive on one screen, because
        // the job is worked externally from that screen and from nothing else.
        $order = $this->submitManualRequest($user, 'nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849A',
            'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 30, 'image/jpeg'),
        ]);

        $this->assertSame('nin_modification', $order->meta['manual_service']);

        $html = $this->actingAs($admin)
            ->get('/admin/manual-orders/'.$order->id)
            ->assertOk()
            ->getContent();

        // The service and the specific correction, in the words the customer
        // picked from, rather than the internal code the order stores.
        $this->assertStringContainsString('NIN Modification', $html);
        $this->assertStringContainsString('Change of Date of Birth', $html);

        // Every answer, and only this branch's answers.
        $this->assertStringContainsString('12345678901', $html);
        $this->assertStringContainsString('SO1250325358849A', $html);
        $this->assertStringContainsString('Auto-generate an email for me', $html);

        // The document travels with the request and is opened through the admin
        // route, so the stored private path is never handed to the browser.
        $this->assertStringContainsString('attestation.jpg', $html);
        $this->assertStringContainsString('/admin/manual-orders/'.$order->id.'/document/attestation_img', $html);

        // Reference, the money taken, the state of the job and whose it is.
        $this->assertStringContainsString((string) $order->provider_reference, $html);
        $this->assertStringContainsString(number_format($order->amount / 100, 2), $html);
        $this->assertStringContainsString('WAITING', $html);
        $this->assertStringContainsString($order->created_at->format('d M Y, h:i A'), $html);
        $this->assertStringContainsString($user->name, $html);
        $this->assertStringContainsString($user->email, $html);

        // The customer who paid cannot read another person's identity document:
        // the route that serves it stands behind the admin gate.
        $this->actingAs($user)
            ->get('/admin/manual-orders/'.$order->id.'/document/attestation_img')
            ->assertForbidden();
    }

    public function test_the_provider_s_own_input_limits_are_the_ones_we_enforce(): void
    {
        Storage::fake('local');
        $user = $this->memberWithBalance(9_000_000);

        // Their tracking box is maxlength=15, not "exactly 15", and the example
        // printed inside it (S7Y0NYFL000038) is fourteen characters. A rule that
        // demanded fifteen would turn away a real enrolment slip, so the length
        // is an upper bound here too, and only the length.
        $this->actingAs($user)->post('/vtu/manual/ipe_clearance', [
            'ipe_type' => 'new_enrollment',
            'tracking_id' => 'S7Y0NYFL000038',
        ])->assertSessionHasNoErrors();

        $this->assertSame('pending', $this->latestOrder($user)->status);

        // A phone correction is the one field whose format their page guards, and
        // it guards it in the browser only. The customer pays for this job by hand,
        // so the same limit has to hold when the request reaches the server: their
        // rule is digits with an optional leading +.
        $this->actingAs($user)->post('/vtu/manual/nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'phone',
            'phone' => '0803-123-4567',
            'consent_agreed' => '1',
        ])->assertSessionHasErrors('phone');

        $this->actingAs($user)->post('/vtu/manual/nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'phone',
            'phone' => '+2348031234567',
            'consent_agreed' => '1',
        ])->assertSessionHasNoErrors();

        // Nothing beyond that is invented: an over-long tracking ID is still the
        // one thing their box cannot hold.
        $this->actingAs($user)->post('/vtu/manual/ipe_clearance', [
            'ipe_type' => 'new_enrollment',
            'tracking_id' => 'ABCDEFGHIJKLMNOPQ',
        ])->assertSessionHasErrors('tracking_id');
    }

    public function test_nothing_beyond_the_providers_own_fields_is_ever_needed(): void
    {
        $user = $this->memberWithBalance(2_000_000);

        // Exactly what each JH Tech screen asks for, and nothing else: no phone
        // number, no email, no names. If a purchase needs more than this, the
        // catalogue has grown a field the provider never asked for.
        $jobs = [
            'ipe_clearance' => ['ipe_type' => 'new_enrollment', 'tracking_id' => 'ABCDEFGHIJKLMNO'],
            'nin_personalization' => ['tracking_id' => 'PQRSTUVWXYZABCD', 'category' => 'get_nin_slip'],
            'nin_slip_print' => ['nin' => '12345678901', 'slip_type' => 'premium_slip'],
            'bvn_print' => ['bvn' => '22334455667', 'consent_agreed' => '1'],
        ];

        foreach ($jobs as $slug => $payload) {
            $this->actingAs($user)
                ->post('/vtu/manual/'.$slug, $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        // ₦3,000 + ₦3,000 + ₦400 + ₦1,000 shipped defaults. The premium slip is
        // charged at the rate the automatic print uses for that same slip, rather
        // than at a second manual price the owner would have to keep in sync.
        $this->assertSame(1_260_000, $this->balance($user));
        $this->assertSame(4, Order::query()->where('user_id', $user->id)->count());

        $personalization = Order::query()
            ->where('user_id', $user->id)
            ->where('meta->manual_service', 'nin_personalization')
            ->sole();

        $this->assertSame(
            ['tracking_id' => 'PQRSTUVWXYZABCD', 'category' => 'get_nin_slip'],
            $personalization->meta['submitted'],
        );
    }

    public function test_the_option_a_customer_picks_decides_what_they_are_billed(): void
    {
        Storage::fake('local');
        $user = $this->memberWithBalance(9_000_000);

        // A date of birth correction costs the provider ₦33,000 on its own while a
        // name change is ₦5,000, so the wallet cannot be debited the same amount
        // for both just because they share one form. Each one also carries the
        // answers its own branch asks for, and the attestation only exists on the
        // date of birth side of that line.
        $name = $this->submitManualRequest($user, 'nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'name',
            'fname' => 'AISHA',
            'lname' => 'MOHAMMED',
        ]);

        $this->assertSame(650_000, (int) $name->amount);
        $this->assertSame(6500.0, (float) $name->meta['base_amount_naira']);

        $birth = $this->submitManualRequest($user, 'nin_modification', [
            'nin' => '12345678901',
            'email_choice' => 'link',
            'link_email' => 'aisha.example@gmail.com',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849A',
            'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg'),
        ]);

        $this->assertSame(4000_000, (int) $birth->amount);
        $this->assertSame(40000.0, (float) $birth->meta['base_amount_naira']);

        // The document belongs to the order it was paid for and reaches the
        // agent's page from there, while the text answers stay readable.
        $upload = $birth->fresh()->meta['uploads']['attestation_img'] ?? [];
        $this->assertNotSame('', (string) ($upload['path'] ?? ''));
        Storage::disk('local')->assertExists((string) $upload['path']);

        $this->assertSame(
            'aisha.example@gmail.com',
            $birth->fresh()->meta['submitted']['link_email'],
        );
        $this->assertArrayNotHasKey('attestation_img', $birth->fresh()->meta['submitted']);
        $this->assertSame([], $name->fresh()->meta['submitted']['uploads'] ?? []);

        // The queue still reads the correction the same way an admin expects.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get('/admin/manual-orders')
            ->assertOk()
            ->assertSee('Change of Date of Birth', false);
    }

    public function test_admin_types_a_result_and_the_customer_reads_it_on_the_receipt(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'bvn_print', [
            'bvn' => '12345678901',
        ]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => 'Your BVN slip is ready. Reference 4471.',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('success', $order->status);
        $this->assertStringContainsString('Reference 4471', $order->meta['result_text']);

        $this->actingAs($user)
            ->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('Reference 4471');
    }

    public function test_admin_upload_is_only_downloadable_by_its_owner(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);
        $stranger = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'nin_slip_print', [
            'nin' => '12345678901',
            'slip_type' => 'long_slip',
        ]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_file' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $order->refresh();
        $this->assertNotEmpty($order->meta['result_file']);
        Storage::disk('local')->assertExists($order->meta['result_file']);

        $this->actingAs($user)
            ->get('/vtu/receipt/'.$order->id.'/result-file')
            ->assertOk()
            ->assertDownload('slip.pdf');

        // A stranger gets the same 404 as a nonexistent order, so the file's
        // existence is not leaked.
        $this->actingAs($stranger)
            ->get('/vtu/receipt/'.$order->id.'/result-file')
            ->assertNotFound();
    }

    public function test_result_text_is_sanitised_before_anyone_sees_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'nin_agreement', [
            'nin' => '12345678901',
            'state' => 'Lagos',
        ]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => '<p>Agreement attached.</p><script>alert(1)</script>',
        ])->assertRedirect();

        $stored = $order->fresh()->meta['result_text'];
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringContainsString('Agreement attached.', $stored);
    }

    public function test_rejection_refunds_the_customer_in_full(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'nin_delink', [
            'nin' => '12345678901',
            'delink_target' => 'delink',
        ]);

        $this->assertNotSame(500_000, $this->balance($user));

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/reject', [
            'reason' => 'The NIN you supplied has no linked line.',
        ])->assertRedirect();

        $this->assertSame(500_000, $this->balance($user));
        $this->assertSame('failed', $order->refresh()->status);
    }

    public function test_a_completed_request_cannot_be_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'nin_agreement', [
            'nin' => '12345678901',
            'state' => 'Lagos',
        ]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => 'Done.',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->from('/admin/manual-orders/'.$order->id)
            ->post('/admin/manual-orders/'.$order->id.'/reject', ['reason' => 'Too late.'])
            ->assertSessionHas('error');

        // The customer keeps the result and no second credit appears.
        $this->assertSame('success', $order->refresh()->status);
        $this->assertLessThan(500_000, $this->balance($user));
    }

    public function test_an_ordinary_order_is_not_part_of_the_manual_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901']);
        $serviceId = $this->latestOrder($user)->service_id;

        $foreign = Order::create([
            'user_id' => $user->id,
            'service_id' => $serviceId,
            'customer_ref' => 'ORD-NOT-MANUAL-7788',
            'amount' => 1000,
            'provider' => 'gsubz',
            'status' => 'pending',
            'meta' => ['type' => 'airtime'],
        ]);

        $this->actingAs($admin)
            ->get('/admin/manual-orders/'.$foreign->id)
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/manual-orders')
            ->assertOk()
            ->assertDontSee('ORD-NOT-MANUAL-7788', false);
    }

    public function test_an_admin_price_and_turnaround_reach_the_customer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/settings', [
            'price_manual_ipe_clearance' => '4500',
            'markup_manual_ipe_clearance' => '500',
            'turnaround_manual_ipe_clearance' => '12',
        ])->assertRedirect();

        $services = app(\App\Services\ManualFulfilmentService::class);
        $this->assertSame(5000.0, $services->totalNaira('ipe_clearance'));
        $this->assertSame('About 12 hours', $services->turnaroundLabel('ipe_clearance'));

        $user = $this->memberWithBalance(900_000);
        $order = $this->submitManualRequest($user, 'ipe_clearance', [
            'ipe_type' => 'inprocessing_error',
            'tracking_id' => 'ABCDEFGHIJKLMNO',
        ]);

        $this->assertSame(400_000, $this->balance($user));
        $this->assertSame(12, $order->meta['turnaround_hours']);

        // The receipt is where the promise is repeated, so it must agree too.
        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('Expected to be ready by', false);
    }

    public function test_the_customer_and_every_admin_are_alerted_the_moment_a_request_arrives(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901']);

        // Both admins get the actionable alert; ordinary members never do.
        Notification::assertSentTo($admin, AdminSystemAlertNotification::class);
        Notification::assertSentTo($otherAdmin, AdminSystemAlertNotification::class);
        Notification::assertNotSentTo($member, AdminSystemAlertNotification::class);
        Notification::assertSentTo($user, ManualOrderNotification::class);
    }

    public function test_the_alerts_use_mail_as_soon_as_a_real_mailer_is_configured(): void
    {
        config(['mail.default' => 'smtp']);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $adminAlert = new AdminSystemAlertNotification('Admin alert', 'body', 'critical', route('admin.manual-orders.index'));
        $customerAlert = new ManualOrderNotification('title', 'body', route('vtu.receipt', 1));

        $this->assertContains('mail', $adminAlert->via($admin));
        $this->assertContains('database', $adminAlert->via($admin));
        $this->assertContains('mail', $customerAlert->via($user));

        config(['mail.default' => 'log']);
        $this->assertNotContains('mail', $adminAlert->via($admin));
        $this->assertNotContains('mail', $customerAlert->via($user));
    }

    public function test_the_customers_notification_links_straight_to_their_receipt(): void
    {
        $user = $this->memberWithBalance(500_000);
        $order = $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901']);

        $notice = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', ManualOrderNotification::class)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(route('vtu.receipt', $order->id), $notice->data['url']);

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee(route('vtu.receipt', $order->id), false);
    }

    public function test_the_admin_alert_links_straight_to_the_waiting_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = $this->memberWithBalance(500_000);

        $order = $this->submitManualRequest($user, 'bvn_print', ['bvn' => '12345678901']);

        $notice = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $admin->id)
            ->where('type', AdminSystemAlertNotification::class)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(route('admin.manual-orders.show', $order->id), $notice->data['url']);
        $this->assertSame('critical', $notice->data['severity']);

        $this->actingAs($admin)->get('/admin/notifications')
            ->assertOk()
            ->assertSee(route('admin.manual-orders.show', $order->id), false);
    }

    private function submitManualRequest(User $user, string $slug, array $payload): Order
    {
        // Services that stand behind terms reject a post that never accepted them,
        // so a customer acting in good faith ticks the box the page puts there.
        if (app(\App\Services\ManualFulfilmentService::class)->consent($slug)['clauses'] !== []) {
            $payload['consent_agreed'] = '1';
        }

        $this->actingAs($user)->post('/vtu/manual/'.$slug, $payload)->assertRedirect();

        return $this->latestOrder($user);
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
