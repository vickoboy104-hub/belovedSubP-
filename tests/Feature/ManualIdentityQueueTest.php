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
            'bvn_print' => ['bvn' => '22334455667'],
        ];

        foreach ($jobs as $slug => $payload) {
            $this->actingAs($user)
                ->post('/vtu/manual/'.$slug, $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        // ₦3,000 + ₦3,000 + ₦1,000 + ₦1,000 shipped defaults.
        $this->assertSame(1_200_000, $this->balance($user));
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
