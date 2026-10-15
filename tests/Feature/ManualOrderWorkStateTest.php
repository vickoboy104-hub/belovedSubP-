<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\ManualOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A paid identity request sits in the queue until a person answers it, and the
 * customer's money has already gone. So an agent needs a way to say "this one is
 * mine now" without pretending the job is done, and without touching the wallet
 * that has already paid for it.
 *
 * Every test here therefore checks the money as well as the label: marking work
 * started must never be a step that could charge, refund or complete anything.
 */
class ManualOrderWorkStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_an_agent_can_take_a_waiting_request_and_the_queue_reads_it_as_in_progress(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$order->id.'/work-state', [
                'state' => 'in_progress',
                'note' => 'NIMC portal opened, waiting on the OTP.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $meta = (array) $order->fresh()->meta;

        $this->assertSame('in_progress', $meta['work_state']);
        $this->assertSame('NIMC portal opened, waiting on the OTP.', $meta['work_note']);
        $this->assertSame($admin->id, $meta['work_state_by']);
        $this->assertNotSame('', (string) ($meta['work_state_at'] ?? ''));

        // The request has not been answered, so it is still pending underneath.
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($admin)
            ->get('/admin/manual-orders')
            ->assertOk()
            ->assertSee('IN PROGRESS')
            ->assertSee('In progress (');
    }

    public function test_the_customer_is_told_work_has_started_and_only_once(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/work-state', ['state' => 'in_progress']);
        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/work-state', [
            'state' => 'in_progress',
            'note' => 'Still waiting on the OTP.',
        ]);

        $started = $this->startedNotifications($user);
        $this->assertCount(1, $started);
        $this->assertStringContainsString('is being worked on', (string) $started[0]->data['title']);
        $this->assertSame(route('vtu.receipt', $order->id), $started[0]->data['url']);

        // Taking the same job again is a correction to our own record, not fresh
        // news for the customer, and the note the next agent reads is the last one
        // written.
        $this->assertSame('Still waiting on the OTP.', (string) $order->fresh()->meta['work_note']);
    }

    public function test_a_request_can_be_handed_back_to_the_queue_without_its_private_note(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/work-state', [
            'state' => 'in_progress',
            'note' => 'Called the customer, no answer.',
        ]);

        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$order->id.'/work-state', ['state' => 'waiting'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $meta = (array) $order->fresh()->meta;

        $this->assertSame('waiting', $meta['work_state']);
        $this->assertSame('', $meta['work_note']);
        $this->assertSame('pending', $order->fresh()->status);

        // Going back is our bookkeeping, so it adds no notification for the
        // customer beyond the one start already sent.
        $this->assertCount(1, $this->startedNotifications($user));

        $this->actingAs($admin)->get('/admin/manual-orders')->assertOk()->assertDontSee('IN PROGRESS');
    }

    public function test_a_request_that_has_been_answered_cannot_be_marked_as_being_worked_on(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $order->forceFill(['status' => 'success'])->save();

        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$order->id.'/work-state', ['state' => 'in_progress'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // A finished request carries its outcome; nothing about who is working it
        // may be written over that.
        $this->assertArrayNotHasKey('work_state', (array) $order->fresh()->meta);
        $this->assertCount(0, $this->startedNotifications($user));
    }

    public function test_marking_work_started_moves_no_money_and_leaves_the_request_refundable(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $balanceBefore = $this->balance($user);
        $ledgerBefore = WalletTransaction::query()->where('wallet_id', $user->wallet->id)->count();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/work-state', ['state' => 'in_progress']);

        $this->assertSame($balanceBefore, $this->balance($user));
        $this->assertSame($ledgerBefore, WalletTransaction::query()->where('wallet_id', $user->wallet->id)->count());

        // The agent gave up on it. A started job is still a paid job that was never
        // done, so the full amount goes back exactly as it would have from the
        // waiting list.
        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$order->id.'/reject', ['reason' => 'The record could not be reached.'])
            ->assertRedirect();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame($balanceBefore + (int) $order->amount, $this->balance($user));
    }

    public function test_only_an_admin_can_say_a_request_is_being_worked_on(): void
    {
        [, $order] = $this->paidRequest();

        // Not even the owner of the record can mark their own request as started.
        $stranger = $this->memberWithBalance(100_000);

        $this->actingAs($stranger)
            ->post('/admin/manual-orders/'.$order->id.'/work-state', ['state' => 'in_progress'])
            ->assertForbidden();

        $this->assertArrayNotHasKey('work_state', (array) $order->fresh()->meta);
    }

    public function test_the_in_progress_tab_lists_the_taken_request_and_the_waiting_tab_keeps_both(): void
    {
        $admin = $this->admin();
        [, $mine] = $this->paidRequest();
        [, $theirs] = $this->paidRequest();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$mine->id.'/work-state', ['state' => 'in_progress']);

        $started = $this->actingAs($admin)->get('/admin/manual-orders?status=in_progress')->assertOk();
        $started->assertSee('#'.$mine->id);
        $started->assertDontSee('#'.$theirs->id);

        // A job somebody has started is still a job the customer is owed an answer
        // for, so it never disappears from the waiting queue.
        $waiting = $this->actingAs($admin)->get('/admin/manual-orders?status=pending')->assertOk();
        $waiting->assertSee('#'.$mine->id);
        $waiting->assertSee('#'.$theirs->id);
    }

    public function test_the_next_request_an_agent_is_offered_is_one_nobody_has_started(): void
    {
        $admin = $this->admin();
        [, $oldest] = $this->paidRequest();
        [, $current] = $this->paidRequest();
        [, $untouched] = $this->paidRequest();

        // The oldest job is already in another agent's hands, so finishing the one
        // in front of me should hand me the third, not the one being worked.
        $this->actingAs($admin)->post('/admin/manual-orders/'.$oldest->id.'/work-state', ['state' => 'in_progress']);

        $page = $this->actingAs($admin)->get('/admin/manual-orders/'.$current->id)->assertOk();

        $this->assertStringContainsString('/admin/manual-orders/'.$untouched->id, $page->getContent());
        $this->assertStringNotContainsString('/admin/manual-orders/'.$oldest->id, $page->getContent());
    }

    public function test_the_work_note_stays_between_agents_and_the_customer_only_hears_that_work_started(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/work-state', [
            'state' => 'in_progress',
            'note' => 'Provider portal is down, retrying after midnight.',
        ]);

        // The agent opening the request next reads both the state and why the
        // last one stopped.
        $detail = $this->actingAs($admin)
            ->get('/admin/manual-orders/'.$order->id)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('IN PROGRESS', $detail);
        $this->assertStringContainsString('Provider portal is down, retrying after midnight.', $detail);

        // None of that is news for the customer, whose receipt still says only
        // that the paid request has not been answered yet.
        $this->actingAs($user)
            ->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertDontSee('Provider portal is down');

        $this->actingAs($user)
            ->get('/notifications')
            ->assertOk()
            ->assertSee('IPE Clearance is being worked on');
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Order}
     */
    private function paidRequest(): array
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->actingAs($user)->post('/vtu/manual/ipe_clearance', [
            'ipe_type' => 'new_enrollment',
            'tracking_id' => 'ABCDEFGHIJKLMNO',
        ])->assertRedirect();

        $order = Order::query()->where('user_id', $user->id)->latest('id')->sole();

        return [$user, $order];
    }

    /**
     * @return list<\Illuminate\Notifications\DatabaseNotification>
     */
    private function startedNotifications(User $user): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', ManualOrderNotification::class)
            ->where('data->type', 'manual_order_started')
            ->get()
            ->all();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }
}
