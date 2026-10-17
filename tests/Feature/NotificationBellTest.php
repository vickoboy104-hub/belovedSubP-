<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdminUserActivityNotification;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notifications were being written all along and nobody could see them: the only
 * way to the list was a link buried in the dashboard, and for an administrator
 * that list was four hundred login alerts deep. These cover the tray in the
 * header - what it counts, and what it deliberately leaves out.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ], $attributes));

        $user->email_verified_at = now();
        $user->save();

        return $user->fresh();
    }

    private function paymentAlert(User $user): object
    {
        $user->notify(new UserWalletActivityNotification(
            'Payment received',
            'N2,950.00 has been added to your wallet from your bank transfer.',
            [
                'type' => 'credit',
                'amount_kobo' => 295_000,
                'reference' => 'FLW_VA_TEST1',
            ],
        ));

        return $user->notifications()->firstOrFail();
    }

    public function test_the_header_carries_an_alerts_button_on_every_signed_in_page(): void
    {
        $user = $this->member();
        $this->paymentAlert($user);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('app-bell', false)
            ->assertSee('Alerts');
    }

    public function test_the_feed_reports_only_the_alerts_that_concern_the_reader(): void
    {
        $user = $this->member();
        $this->paymentAlert($user);

        // The operational noise an administrator receives about everybody else.
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminUserActivityNotification::class,
            'data' => ['title' => 'User Login', 'message' => 'someone signed in'],
        ]);

        $feed = $this->actingAs($user)->getJson('/notifications/feed')->assertOk()->json();

        $this->assertSame(1, $feed['unread']);
        $this->assertCount(1, $feed['notifications']);
        $this->assertSame('Payment received', $feed['notifications'][0]['title']);
        $this->assertSame('+₦2,950.00', $feed['notifications'][0]['amount']);
        $this->assertTrue($feed['notifications'][0]['unread']);
        $this->assertStringContainsString('/wallet/transactions', $feed['notifications'][0]['url']);
    }

    public function test_opening_an_alert_clears_it_from_the_badge(): void
    {
        $user = $this->member();
        $notice = $this->paymentAlert($user);

        $this->actingAs($user)
            ->postJson('/notifications/'.$notice->id.'/read')
            ->assertOk()
            ->assertJson(['ok' => true, 'unread' => 0]);

        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_nobody_else_can_settle_your_alerts(): void
    {
        $user = $this->member();
        $notice = $this->paymentAlert($user);
        $stranger = $this->member(['email' => 'stranger@example.com']);

        $this->actingAs($stranger)
            ->postJson('/notifications/'.$notice->id.'/read')
            ->assertOk();

        $this->assertNull($notice->fresh()->read_at);
        $this->assertSame(1, $this->actingAs($user)->getJson('/notifications/feed')->json('unread'));
    }

    public function test_the_full_page_shows_the_same_list_as_the_tray(): void
    {
        $user = $this->member();
        $this->paymentAlert($user);

        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminUserActivityNotification::class,
            'data' => ['title' => 'User Login', 'message' => 'someone signed in'],
        ]);

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee('Payment received')
            ->assertDontSee('User Login');
    }
}
