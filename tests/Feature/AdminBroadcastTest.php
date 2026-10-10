<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdminBroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> Guards the sequence so two calls never reuse an email. */
    private array $created = [];

    private function admin(): User
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'first_name' => 'Vicko',
            'last_name' => 'Owner',
            'email' => 'owner@belovedsubp.test',
            'phone' => null,
        ]);

        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    /** @return array<int, User> */
    private function members(int $count): array
    {
        $members = [];

        for ($offset = 0; $offset < $count; $offset++) {
            $index = count($this->created);
            $this->created[] = $index;

            $member = User::factory()->create([
                'is_admin' => false,
                'first_name' => 'Member',
                'last_name' => (string) $index,
                'email' => 'member'.$index.'@belovedsubp.test',
                'phone' => '0803123456'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);

            $member->email_verified_at = now();
            $member->save();

            $members[] = $member;
        }

        return $members;
    }

    public function test_only_an_admin_can_open_the_announcement_page(): void
    {
        $this->get('/admin/broadcast')->assertRedirect('/login');

        $member = $this->members(1)[0];
        $this->actingAs($member)->get('/admin/broadcast')->assertForbidden();

        $this->actingAs($this->admin())
            ->get('/admin/broadcast')
            ->assertOk()
            ->assertSee('Announcements');
    }

    public function test_a_broadcast_lands_in_every_account_inbox_and_shows_there(): void
    {
        $admin = $this->admin();
        $members = $this->members(3);

        $response = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Service window on Friday',
            'message' => 'We close at 6pm and reopen at 9am.',
            'channels' => ['database'],
            'after' => 0,
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'sent' => 4,
            'has_more' => false,
            'remaining' => 0,
        ]);

        $this->assertSame(4, DatabaseNotification::query()->where('type', AdminBroadcastNotification::class)->count());

        foreach ($members as $member) {
            $this->assertStringContainsString(
                'Service window on Friday',
                $this->actingAs($member)->get('/notifications')->assertOk()->getContent()
            );
        }
    }

    public function test_delivery_walks_the_table_one_batch_at_a_time(): void
    {
        $admin = $this->admin();
        $this->members(40);

        $first = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Wallet upgrade',
            'message' => 'The wallet ledger moved to a new table.',
            'channels' => ['database'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertSame(25, $first['sent']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(16, $first['remaining']);

        $second = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Wallet upgrade',
            'message' => 'The wallet ledger moved to a new table.',
            'channels' => ['database'],
            'after' => $first['after'],
        ])->assertOk()->json();

        $this->assertSame(16, $second['sent']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(0, $second['remaining']);

        $this->assertSame(41, DatabaseNotification::query()->where('type', AdminBroadcastNotification::class)->count());
    }

    public function test_email_is_dropped_when_the_server_cannot_send_it(): void
    {
        $admin = $this->admin();
        $this->members(2);

        // The out-of-the-box test transport discards everything it is handed,
        // which is exactly the behaviour the readiness gate refuses to call delivery.
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $logged = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Maintenance',
            'message' => 'A short maintenance window starts tonight.',
            'channels' => ['database', 'mail'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertTrue($logged['mailSkipped']);
        $this->assertSame(0, $logged['emails']);
        $this->assertCount(0, $transport->messages());

        // The announcement still has to reach every inbox in-app.
        $this->assertSame(3, DatabaseNotification::query()->where('type', AdminBroadcastNotification::class)->count());
    }

    public function test_email_reaches_every_account_once_a_real_mailer_is_configured(): void
    {
        $inbox = new ArrayTransport();

        // A live host is judged by the transport it can actually use, not by a
        // driver name, so the collector is registered under a ready-looking name.
        Mail::extend('collector', fn () => $inbox);
        config([
            'mail.mailers.collector' => ['transport' => 'collector'],
            'mail.default' => 'collector',
        ]);

        $admin = $this->admin();
        $this->members(2);

        $sent = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Maintenance',
            'message' => 'A short maintenance window starts tonight.',
            'channels' => ['database', 'mail'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertFalse($sent['mailSkipped']);
        $this->assertSame(3, $sent['emails']);
        $this->assertCount(3, $inbox->messages());

        $this->assertSame(['Maintenance'], $inbox->messages()
            ->map(fn ($message) => $message->getOriginalMessage()->getSubject())
            ->unique()
            ->values()
            ->all());

        $this->assertSame([
            'member0@belovedsubp.test',
            'member1@belovedsubp.test',
            'owner@belovedsubp.test',
        ], $inbox->messages()
            ->flatMap(fn ($message) => $message->getOriginalMessage()->getTo())
            ->map(fn ($address) => $address->getAddress())
            ->sort()
            ->values()
            ->all());

        $this->assertStringContainsString(
            'A short maintenance window starts tonight.',
            $inbox->messages()->first()->getOriginalMessage()->toString(),
        );
    }

    public function test_the_recipient_export_normalises_nigerian_numbers(): void
    {
        $admin = $this->admin();

        User::factory()->create(['is_admin' => false, 'phone' => '08031234567', 'email' => 'a@test.test']);
        User::factory()->create(['is_admin' => false, 'phone' => '+234 803 123 4567', 'email' => 'b@test.test']);
        User::factory()->create(['is_admin' => false, 'phone' => '002348031234567', 'email' => 'c@test.test']);

        $response = $this->actingAs($admin)->get('/admin/broadcast/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('name,phone,e164', $csv);
        $this->assertStringContainsString('+2348031234567', $csv);
        $this->assertSame(3, substr_count($csv, '+2348031234567'));
    }

    public function test_an_unusable_broadcast_is_rejected_before_any_account_is_disturbed(): void
    {
        $admin = $this->admin();
        $this->members(2);

        $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => '',
            'message' => '',
            'channels' => [],
        ])->assertStatus(422);

        $this->assertSame(0, DatabaseNotification::query()->count());
    }
}
