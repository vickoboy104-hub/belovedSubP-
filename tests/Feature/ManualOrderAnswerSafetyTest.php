<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\ManualOrderNotification;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A paid identity request can only be answered once, and only by the person holding
 * it or by an agent who says why they are stepping over that hold.
 *
 * Two screens open on the same request, a double click, a page that was loaded
 * before somebody else finished the job: all of them must leave one result, one
 * refund, one announcement to the customer. And when the database itself refuses
 * the write, the request must not end up half answered - no result with no
 * history, no assignment with no explanation, no failed request whose refund
 * vanished.
 */
class ManualOrderAnswerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ?string $refusal = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->repairDatabase();

        parent::tearDown();
    }

    public function test_a_second_answer_to_the_same_request_announces_it_no_twice(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        $this->actingAs($ada)
            ->post($this->fulfilUrl($order), ['result_text' => 'Your IPE is 1111222233.'])
            ->assertSessionHas('success');

        $this->assertCount(1, $this->notices($user, 'manual_order_completed'));

        // Ben was looking at the request before Ada answered it. His submit corrects
        // the published result - the customer must not be told twice that a request
        // they have already read the answer to is suddenly ready.
        $this->actingAs($ben)
            ->post($this->fulfilUrl($order), [
                'result_text' => 'Your IPE is 1111222233, and the line closes on Friday.',
                'override_reason' => 'Ada is off duty; the customer asked for the closing date.',
            ])
            ->assertSessionHas('success');

        $answered = $order->fresh();

        $this->assertSame('success', $answered->status);
        $this->assertCount(1, $this->notices($user, 'manual_order_completed'));
        $this->assertStringContainsString('closes on Friday', (string) $answered->meta['result_text']);
        $this->assertSame($ben->id, (int) $answered->meta['fulfilled_by']);
    }

    public function test_the_second_conditional_answer_write_changes_nothing_at_the_database(): void
    {
        $ada = $this->admin('Ada');
        [, $order] = $this->paidRequest();

        // The statement both agents issue at once: write the answer only while the
        // request is still in the state the page they were shown described.
        $first = $this->answerCas($order->id, 'pending', [
            'result_text' => 'Ada answered.',
            'fulfilled_by' => $ada->id,
        ]);
        $second = $this->answerCas($order->id, 'pending', [
            'result_text' => 'Ben answered.',
            'fulfilled_by' => $ada->id + 1,
        ]);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);

        $held = $order->fresh();
        $this->assertSame('success', $held->status);
        $this->assertSame('Ada answered.', (string) $held->meta['result_text']);
        $this->assertSame($ada->id, (int) $held->meta['fulfilled_by']);
    }

    public function test_a_request_rejected_once_is_refunded_once_and_mourned_once(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [$user, $order] = $this->paidRequest();

        $charged = (int) $order->amount;
        $afterRefund = $this->balance($user) + $charged;

        $this->actingAs($ada)
            ->post($this->rejectUrl($order), ['reason' => 'NIMC has no record for that NIN.'])
            ->assertSessionHas('success');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame($afterRefund, $this->balance($user));

        // Ben's page still offers the reject button. Answering the same request the
        // other way must not be able to send the customer's money around twice.
        $this->actingAs($ben)
            ->post($this->rejectUrl($order), ['reason' => 'Same thing, my turn.'])
            ->assertSessionHas('error');

        $this->assertSame($afterRefund, $this->balance($user));
        $this->assertCount(1, $this->refunds($user));
        $this->assertCount(1, $this->notices($user, 'manual_order_rejected'));
    }

    public function test_a_refunded_request_cannot_also_be_given_a_result(): void
    {
        $ada = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)
            ->post($this->rejectUrl($order), ['reason' => 'The provider is offline for the week.'])
            ->assertSessionHas('success');

        $this->actingAs($ada)
            ->post($this->fulfilUrl($order), ['result_text' => 'Actually it worked.'])
            ->assertSessionHas('error');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCount(1, $this->refunds($user));
        $this->assertCount(0, $this->notices($user, 'manual_order_completed'));
        $this->assertCount(1, $this->notices($user, 'manual_order_rejected'));
    }

    public function test_a_completed_request_cannot_be_rejected_into_a_second_refund(): void
    {
        $ada = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->fulfilUrl($order), ['result_text' => 'Done.'])
            ->assertSessionHas('success');

        $this->actingAs($ada)
            ->post($this->rejectUrl($order), ['reason' => 'Changed my mind.'])
            ->assertSessionHas('error');

        $this->assertSame('success', $order->fresh()->status);
        $this->assertCount(0, $this->refunds($user));
        $this->assertCount(1, $this->notices($user, 'manual_order_completed'));
        $this->assertCount(0, $this->notices($user, 'manual_order_rejected'));
    }

    public function test_answering_over_another_agents_hold_demands_the_reason_first(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        $this->actingAs($ben)
            ->post($this->fulfilUrl($order), ['result_text' => 'I finished it.'])
            ->assertSessionHas('error');

        $waiting = $order->fresh();
        $this->assertSame('pending', $waiting->status);
        $this->assertSame($ada->id, (int) $waiting->assigned_to);
        $this->assertArrayNotHasKey('result_text', (array) $waiting->meta);
        $this->assertCount(0, $this->notices($user, 'manual_order_completed'));
        $this->assertCount(1, $this->handovers($waiting));

        $this->actingAs($ben)
            ->post($this->fulfilUrl($order), [
                'result_text' => 'I finished it while Ada was away.',
                'override_reason' => 'Ada has been off since Tuesday and the customer is overdue.',
            ])
            ->assertSessionHas('success');

        $answered = $order->fresh();
        $this->assertSame('success', $answered->status);
        $this->assertCount(1, $this->notices($user, 'manual_order_completed'));

        $event = $this->handovers($answered)[1];
        $this->assertSame('answered_over', $event['action']);
        $this->assertSame('Ben', $event['by_name']);
        $this->assertSame('Ada', $event['from_name']);
        $this->assertSame('Ada has been off since Tuesday and the customer is overdue.', $event['note']);
    }

    public function test_a_refused_answer_does_not_keep_the_document_it_was_handed(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        // Ben tries to close it with a document and no reason. The attempt is refused
        // before anything is written, so no identity document may be left sitting on
        // the private disk attached to nothing.
        $this->actingAs($ben)
            ->post($this->fulfilUrl($order), [
                'result_file' => UploadedFile::fake()->create('result.pdf', 12, 'application/pdf'),
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $this->storedResults());
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_page_asks_for_the_reason_only_when_it_would_step_over_someone(): void
    {
        $ada = $this->admin('Ada');
        $ben = $this->admin('Ben');
        [, $order] = $this->paidRequest();

        // Nothing held and nothing answered: no reason to demand.
        $this->assertStringNotContainsString(
            'override_reason',
            $this->actingAs($ben)->get($this->showUrl($order))->getContent(),
        );

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        $over = $this->actingAs($ben)->get($this->showUrl($order))->getContent();
        $this->assertStringContainsString('name="override_reason"', $over);
        $this->assertStringContainsString('Why are you answering this over Ada?', $over);

        // Ada is only correcting her own work, so the field stays out of her way.
        $this->assertStringNotContainsString(
            'override_reason',
            $this->actingAs($ada)->get($this->showUrl($order))->getContent(),
        );

        $this->actingAs($ada)->post($this->fulfilUrl($order), ['result_text' => 'Your IPE is 4444.']);

        $amend = $this->actingAs($ben)->get($this->showUrl($order))->getContent();
        $this->assertStringContainsString('name="override_reason"', $amend);
        $this->assertStringContainsString('Why are you changing the result Ada published?', $amend);
    }

    public function test_a_writing_database_leaves_the_claim_unheld_and_the_history_clean(): void
    {
        $ada = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        // The one failure mode an agent must never be able to cause: their name on
        // the request with no record of why, or a line of history naming a holder
        // that the database never accepted.
        $this->refuseUpdatesOn('orders');

        $this->actingAs($ada)
            ->post($this->claimUrl($order), ['action' => 'take', 'note' => 'Portal opened.'])
            ->assertSessionHas('error');

        $untouched = $order->fresh();
        $this->assertNull($untouched->assigned_to);
        $this->assertNull($untouched->assigned_at);
        $this->assertArrayNotHasKey('work_history', (array) $untouched->meta);
        $this->assertArrayNotHasKey('work_note', (array) $untouched->meta);
        $this->assertCount(0, $this->notices($user, 'manual_order_started'));

        // A recovered database does not resurrect the refused attempt. The agent has
        // to take the request again, and this time the hold and the line of history
        // that explains it arrive in the same write.
        $this->repairDatabase();

        $this->actingAs($ada)
            ->post($this->claimUrl($order), ['action' => 'take', 'note' => 'Portal opened.'])
            ->assertSessionHas('success');

        $held = $order->fresh();
        $this->assertSame($ada->id, (int) $held->assigned_to);
        $this->assertCount(1, $this->handovers($held));
        $this->assertSame('Portal opened.', $this->handovers($held)[0]['note']);
        $this->assertCount(1, $this->notices($user, 'manual_order_started'));
    }

    public function test_a_writing_database_rolls_an_answer_back_to_the_wait(): void
    {
        $ada = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        $this->actingAs($ada)->post($this->claimUrl($order), ['action' => 'take']);

        $this->refuseUpdatesOn('orders');

        $this->actingAs($ada)
            ->post($this->fulfilUrl($order), ['result_text' => 'Your IPE is 9999.'])
            ->assertSessionHas('error');

        $waiting = $order->fresh();
        $this->assertSame('pending', $waiting->status);
        $this->assertArrayNotHasKey('result_text', (array) $waiting->meta);
        $this->assertArrayNotHasKey('fulfilled_at', (array) $waiting->meta);
        // Nothing was announced, because nothing was saved.
        $this->assertCount(0, $this->notices($user, 'manual_order_completed'));
        $this->assertCount(1, $this->handovers($waiting));

        // And the request is still answerable afterwards, with exactly one notice for
        // the one result the customer is given.
        $this->repairDatabase();

        $this->actingAs($ada)
            ->post($this->fulfilUrl($order), ['result_text' => 'Your IPE is 9999.'])
            ->assertSessionHas('success');

        $this->assertSame('success', $order->fresh()->status);
        $this->assertCount(1, $this->notices($user, 'manual_order_completed'));
    }

    public function test_a_refund_the_database_refuses_leaves_the_request_waiting(): void
    {
        $ada = $this->admin('Ada');
        [$user, $order] = $this->paidRequest();

        $before = $this->balance($user);

        $this->refuseInsertsOn('wallet_transactions');

        $this->actingAs($ada)
            ->post($this->rejectUrl($order), ['reason' => 'Ledger is down.'])
            ->assertSessionHas('error');

        $rejected = $order->fresh();
        $this->assertSame('pending', $rejected->status);
        $this->assertArrayNotHasKey('reject_reason', (array) $rejected->meta);
        $this->assertSame($before, $this->balance($user));
        $this->assertCount(0, $this->refunds($user));
        $this->assertCount(0, $this->notices($user, 'manual_order_rejected'));

        // Once the ledger can write again, the same attempt refunds the paid amount
        // once, and only once, for the request that is still outstanding.
        $this->repairDatabase();

        $this->actingAs($ada)
            ->post($this->rejectUrl($order), ['reason' => 'Ledger is down.'])
            ->assertSessionHas('success');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCount(1, $this->refunds($user));
        $this->assertSame($before + (int) $order->amount, $this->balance($user));
        $this->assertCount(1, $this->notices($user, 'manual_order_rejected'));
    }

    private function claimUrl(Order $order): string
    {
        return '/admin/manual-orders/'.$order->id.'/claim';
    }

    private function fulfilUrl(Order $order): string
    {
        return '/admin/manual-orders/'.$order->id.'/fulfil';
    }

    private function rejectUrl(Order $order): string
    {
        return '/admin/manual-orders/'.$order->id.'/reject';
    }

    private function showUrl(Order $order): string
    {
        return '/admin/manual-orders/'.$order->id;
    }

    /**
     * The same statement the controller issues to answer a waiting request, so a
     * test can show what two of them racing at the database actually do.
     *
     * @param  array<string, mixed>  $meta
     */
    private function answerCas(int $orderId, string $wasStatus, array $meta): int
    {
        return Order::query()
            ->where('id', $orderId)
            ->where('status', $wasStatus)
            ->update(['status' => 'success', 'meta' => json_encode($meta)]);
    }

    /**
     * Make every write to a table fail, the way a dead connection or a locked
     * server does, after the row itself has already been created.
     */
    private function refuseUpdatesOn(string $table): void
    {
        $this->breakSql($name = 'refuse_'.$table.'_update', "BEFORE UPDATE ON {$table}");

        $this->refusal = $name;
    }

    private function refuseInsertsOn(string $table): void
    {
        $this->breakSql($name = 'refuse_'.$table.'_insert', "BEFORE INSERT ON {$table}");

        $this->refusal = $name;
    }

    private function breakSql(string $name, string $when): void
    {
        DB::unprepared('CREATE TRIGGER '.$name.' '.$when." BEGIN SELECT RAISE(ABORT, 'the database refused the write'); END");
    }

    /**
     * Give the database back its ability to write, so a test can show that the
     * refused action left the request exactly as it was and retries cleanly.
     */
    private function repairDatabase(): void
    {
        if ($this->refusal !== null) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->refusal);
            $this->refusal = null;
        }
    }

    /**
     * @return list<string>
     */
    private function storedResults(): array
    {
        return Storage::disk('local')->exists('manual-results')
            ? Storage::disk('local')->files('manual-results')
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function handovers(Order $order): array
    {
        $meta = (array) $order->fresh()->meta;

        return array_values(is_array($meta['work_history'] ?? null) ? $meta['work_history'] : []);
    }

    private function refunds(User $user): array
    {
        return WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->where('type', 'credit')
            ->where('reference', 'like', 'REFUND-%')
            ->get()
            ->all();
    }

    /**
     * @return list<\Illuminate\Notifications\DatabaseNotification>
     */
    private function notices(User $user, string $kind): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', ManualOrderNotification::class)
            ->where('data->type', $kind)
            ->get()
            ->all();
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

        return [$user, $order->fresh()];
    }

    private function admin(string $name): User
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
