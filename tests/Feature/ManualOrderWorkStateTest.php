<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\ManualOrderNotification;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A paid identity request sits in the queue until a person answers it, and the
 * customer's money has already gone. So an agent needs a way to say "this one is
 * mine now" without pretending the job is done, without touching the wallet that
 * has already paid for it, and without a second agent silently taking the same
 * record.
 *
 * Every test here therefore checks the money as well as the label: holding a
 * request must never be a step that could charge, refund or complete anything.
 */
class ManualOrderWorkStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_an_agent_takes_a_waiting_request_and_the_customer_reads_it_as_in_progress(): void
    {
        $admin = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)
            ->post($this->claimUrl($order), ['action' => 'take', 'note' => 'NIMC portal opened, waiting on the OTP.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $held = $order->fresh();

        $this->assertSame($admin->id, (int) $held->assigned_to);
        $this->assertNotNull($held->assigned_at);
        // The request has not been answered, so it is still pending underneath.
        $this->assertSame('pending', $held->status);

        $meta = (array) $held->meta;
        $this->assertSame('NIMC portal opened, waiting on the OTP.', $meta['work_note']);
        $this->assertCount(1, $meta['work_history']);
        $this->assertSame('take', $meta['work_history'][0]['action']);
        $this->assertSame('Ada', $meta['work_history'][0]['by_name']);

        $this->actingAs($admin)
            ->get('/admin/manual-orders')
            ->assertOk()
            ->assertSee('IN PROGRESS')
            ->assertSee('In progress (')
            ->assertSee('Held by Ada');

        // The customer's own words, on the receipt and in the ledger.
        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->assertSee('IN PROGRESS');
        $this->actingAs($user)->get('/vtu/orders')->assertOk()->assertSee('In Progress');
    }

    public function test_a_request_is_waiting_until_somebody_takes_it(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->assertSame(
            ['key' => 'pending', 'label' => 'Waiting', 'tone' => 'warning'],
            $order->customerStatus(),
        );

        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->assertSee('WAITING');
        $this->actingAs($user)->get('/vtu/orders')->assertOk()->assertSee('Waiting');
        $this->actingAs($admin)->get('/admin/manual-orders')->assertOk()->assertSee('WAITING');
    }

    public function test_two_agents_cannot_both_end_up_holding_the_same_request(): void
    {
        $first = $this->admin('Ada');
        $second = $this->admin('Ben');
        [, $order] = $this->paidRequest();

        // Both were shown the request while it was still unclaimed. Ada moves first.
        $this->actingAs($first)->post($this->claimUrl($order), ['action' => 'take'])->assertSessionHas('success');

        // Ben's page is stale, but claiming must not be a plain overwrite.
        $this->actingAs($second)
            ->post($this->claimUrl($order), ['action' => 'take'])
            ->assertSessionHas('error');

        $this->assertSame($first->id, (int) $order->fresh()->assigned_to);
        $this->assertCount(1, (array) $order->fresh()->meta['work_history']);
    }

    public function test_the_second_conditional_write_of_a_race_changes_nothing_at_the_database(): void
    {
        $admin = $this->admin();
        [, $order] = $this->paidRequest();

        // The claim is one compare-and-set: the write only lands while the row still
        // holds what the agent was shown. This is the statement two racing agents
        // both issue, and only the first of them can find the row unclaimed.
        $first = $this->cas($order->id, $admin->id);
        $second = $this->cas($order->id, $admin->id + 1);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame($admin->id, (int) $order->fresh()->assigned_to);
    }

    public function test_a_hold_can_be_released_and_another_agent_picks_it_up(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take', 'note' => 'Called the customer, no answer.']);

        $this->actingAs($ada)
            ->post($this->claimUrl($order), ['action' => 'release'])
            ->assertSessionHas('success');

        $released = $order->fresh();
        $this->assertNull($released->assigned_to);
        $this->assertNull($released->assigned_at);
        $this->assertSame('', (string) $released->meta['work_note']);
        $this->assertSame('pending', $released->status);

        // Back in the shared queue for anybody, and the customer hears nothing new.
        $this->assertCount(1, $this->startedNotifications($user));

        $this->actingAs($ben)->post($this->claimUrl($order), ['action' => 'take'])->assertSessionHas('success');
        $this->assertSame($ben->id, (int) $order->fresh()->assigned_to);

        $events = $this->handovers($order);
        $this->assertCount(3, $events);
        $this->assertSame('release', $events[1]['action']);
        $this->assertSame('take', $events[2]['action']);
        $this->assertSame('Ben', $events[2]['by_name']);
        $this->assertNull($events[2]['from']);
    }

    public function test_taking_over_another_agent_s_request_needs_a_reason_and_records_both_names(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take', 'note' => 'Portal is down, retrying later.']);

        $this->actingAs($ben)
            ->post($this->claimUrl($order), ['action' => 'take_over'])
            ->assertSessionHas('error');

        $this->assertSame($ada->id, (int) $order->fresh()->assigned_to);

        $this->actingAs($ben)
            ->post($this->claimUrl($order), ['action' => 'take_over', 'note' => 'Ada is off duty, I have the portal open.'])
            ->assertSessionHas('success');

        $moved = $order->fresh();
        $this->assertSame($ben->id, (int) $moved->assigned_to);

        $events = $this->handovers($moved);
        $this->assertCount(2, $events);
        $this->assertSame('take_over', $events[1]['action']);
        $this->assertSame('Ben', $events[1]['by_name']);
        $this->assertSame('Ada', $events[1]['from_name']);
        $this->assertSame('Ada is off duty, I have the portal open.', $events[1]['note']);

        // The reason travels with the request, and the detail page shows the chain.
        $detail = $this->actingAs($ben)->get('/admin/manual-orders/'.$order->id)->assertOk()->getContent();
        $this->assertStringContainsString('Changes of hand', $detail);
        $this->assertStringContainsString('Ada is off duty, I have the portal open.', $detail);
    }

    public function test_an_authorized_agent_can_release_a_request_for_someone_else_but_must_say_why(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        $this->actingAs($ben)
            ->post($this->claimUrl($order), ['action' => 'release'])
            ->assertSessionHas('error');
        $this->assertSame($ada->id, (int) $order->fresh()->assigned_to);

        $this->actingAs($ben)
            ->post($this->claimUrl($order), ['action' => 'release', 'note' => 'Ada has been off since Friday, back to the queue.'])
            ->assertSessionHas('success');

        $released = $order->fresh();
        $this->assertNull($released->assigned_to);
        $this->assertSame('Ben', $this->handovers($released)[1]['by_name']);
        $this->assertSame('Ada', $this->handovers($released)[1]['from_name']);
    }

    public function test_releasing_a_request_nobody_holds_is_refused(): void
    {
        $admin = $this->admin();
        [, $order] = $this->paidRequest();

        $this->actingAs($admin)
            ->post($this->claimUrl($order), ['action' => 'release', 'note' => 'Anything'])
            ->assertSessionHas('error');

        $this->assertNull($order->fresh()->assigned_to);
        $this->assertCount(0, $this->handovers($order->fresh()));
    }

    public function test_the_customer_is_told_work_has_started_and_only_once(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post($this->claimUrl($order), ['action' => 'take']);
        $this->actingAs($admin)->post($this->claimUrl($order), ['action' => 'take', 'note' => 'Still waiting on the OTP.']);

        $started = $this->startedNotifications($user);
        $this->assertCount(1, $started);
        $this->assertStringContainsString('is being worked on', (string) $started[0]->data['title']);
        $this->assertSame(route('vtu.receipt', $order->id), $started[0]->data['url']);

        // Holding the same job again is a correction to our own record, not fresh
        // news for the customer, and the note the next agent reads is the last one
        // written.
        $this->assertSame('Still waiting on the OTP.', (string) $order->fresh()->meta['work_note']);
        // Saving a note on a job already held is not a change of hand.
        $this->assertCount(1, $this->handovers($order->fresh()));
    }

    public function test_a_request_that_has_been_answered_cannot_be_taken_or_held(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $order->forceFill(['status' => 'success'])->save();

        $this->actingAs($admin)
            ->post($this->claimUrl($order), ['action' => 'take'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // A finished request carries its outcome; nothing about who is working it
        // may be written over that.
        $this->assertNull($order->fresh()->assigned_to);
        $this->assertCount(0, $this->handovers($order->fresh()));
        $this->assertCount(0, $this->startedNotifications($user));
    }

    public function test_holding_a_request_moves_no_money_and_leaves_it_refundable(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $balanceBefore = $this->balance($user);
        $ledgerBefore = WalletTransaction::query()->where('wallet_id', $user->wallet->id)->count();

        $this->actingAs($admin)->post($this->claimUrl($order), ['action' => 'take']);

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

        // And the customer is told the money came back, on the receipt itself.
        $this->actingAs($user)
            ->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('FAILED')
            ->assertSee('Refunded to your wallet')
            ->assertSee('The record could not be reached.');
    }

    public function test_a_claimed_request_is_still_completed_by_the_agent_holding_it(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post($this->claimUrl($order), ['action' => 'take']);

        $this->actingAs($admin)
            ->post('/admin/manual-orders/'.$order->id.'/fulfil', ['result_text' => 'Your IPE is 12345678.'])
            ->assertRedirect();

        $done = $order->fresh();
        $this->assertSame('success', $done->status);
        $this->assertSame(
            ['key' => 'success', 'label' => 'Success', 'tone' => 'success'],
            $done->customerStatus(),
        );
        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->assertSee('SUCCESS');
    }

    public function test_only_an_admin_can_hold_a_request(): void
    {
        [, $order] = $this->paidRequest();

        // Not even the owner of the record can mark their own request as started.
        $stranger = $this->memberWithBalance(100_000);

        $this->actingAs($stranger)
            ->post($this->claimUrl($order), ['action' => 'take'])
            ->assertForbidden();

        $this->assertNull($order->fresh()->assigned_to);
    }

    public function test_the_in_progress_tab_lists_the_taken_request_and_the_waiting_tab_keeps_both(): void
    {
        $admin = $this->admin();
        [, $mine] = $this->paidRequest();
        [, $theirs] = $this->paidRequest();

        $this->actingAs($admin)->post($this->claimUrl($mine), ['action' => 'take']);

        $started = $this->actingAs($admin)->get('/admin/manual-orders?status=in_progress')->assertOk();
        $started->assertSee('#'.$mine->id);
        $started->assertDontSee('#'.$theirs->id);

        // A job somebody has started is still a job the customer is owed an answer
        // for, so it never disappears from the waiting queue.
        $waiting = $this->actingAs($admin)->get('/admin/manual-orders?status=pending')->assertOk();
        $waiting->assertSee('#'.$mine->id);
        $waiting->assertSee('#'.$theirs->id);
    }

    public function test_the_next_request_an_agent_is_offered_is_one_nobody_has_taken(): void
    {
        $admin = $this->admin();
        [, $oldest] = $this->paidRequest();
        [, $current] = $this->paidRequest();
        [, $untouched] = $this->paidRequest();

        // The oldest job is already in another agent's hands, so finishing the one
        // in front of me should hand me the third, not the one being worked.
        $this->actingAs($admin)->post($this->claimUrl($oldest), ['action' => 'take']);

        $page = $this->actingAs($admin)->get('/admin/manual-orders/'.$current->id)->assertOk();

        $this->assertStringContainsString('/admin/manual-orders/'.$untouched->id, $page->getContent());
        $this->assertStringNotContainsString('/admin/manual-orders/'.$oldest->id, $page->getContent());
    }

    public function test_the_work_note_stays_between_agents_and_the_customer_only_hears_that_work_started(): void
    {
        $admin = $this->admin();
        [$user, $order] = $this->paidRequest();

        $this->actingAs($admin)->post($this->claimUrl($order), [
            'action' => 'take',
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

    private function claimUrl(Order $order): string
    {
        return '/admin/manual-orders/'.$order->id.'/claim';
    }

    /**
     * The same statement the controller issues to take an unclaimed request.
     */
    private function cas(int $orderId, int $adminId): int
    {
        return Order::query()
            ->where('id', $orderId)
            ->where('status', 'pending')
            ->whereNull('assigned_to')
            ->update(['assigned_to' => $adminId, 'assigned_at' => now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function handovers(Order $order): array
    {
        $meta = (array) $order->fresh()->meta;

        return array_values(is_array($meta['work_history'] ?? null) ? $meta['work_history'] : []);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Order}
     */
    private function paidRequest(): array
    {
        $user = $this->memberWithBalance(5_000_000);

        $this->actingAs($user)->post('/vtu/manual/ipe_clearance', [
            PurchaseIntent::FIELD => PurchaseIntent::mint(),
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

    private function admin(string $name = 'Ada'): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => $name]);
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
