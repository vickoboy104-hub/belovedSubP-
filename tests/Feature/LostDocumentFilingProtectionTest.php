<?php

namespace Tests\Feature;

use App\Models\DocumentFiling;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ManualDocumentStore;
use App\Services\PurchaseIntent;
use App\Services\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A request whose paper could not be found is the one case a machine is not
 * allowed to finish: the customer has paid, the document is missing, and the only
 * remaining choices - chase the customer or hand the money back - belong to a
 * person. So the recovery marks it LOST and names an agent, and the folder it
 * leaves behind is the agent's evidence of what was actually received.
 *
 * The holding area is swept by an ordinary request: every customer submission
 * clears folders older than an hour. A LOST case is exactly the kind of folder
 * that has been sitting for an hour, so if the sweep only looked for paid orders
 * still waiting, it would delete the evidence in front of the agent working the
 * case and turn a salvageable request into a refund nobody chose.
 *
 * These tests hold that line: a LOST case keeps its folder and its contents
 * against every sweep, against every further recovery pass, and against a
 * customer who would rather it were gone; and it stops being protected only when
 * an authorised agent completes the request or reverses it.
 */
class LostDocumentFilingProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = 'nin_modification';

    /** Where a request holds its paper until the order it was bought for is answered. */
    private const HOLDING = 'manual-uploads/.pending';

    private const CHARGE_KOBO = 4_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Notification::fake();
    }

    /**
     * The state a case reaches when the paper really has gone: three recovery
     * passes found nothing, an agent has been told, and the money has not moved.
     *
     * The folder is then given its contents back and aged two hours, because the
     * case worth testing is not an empty directory - it is the customer's
     * attestation still lying in the holding area while the record says it could
     * not be filed, and old enough that every sweep in the book counts it as a
     * candidate.
     *
     * @return array{0: Order, 1: string, 2: string}  order, folder token, held path
     */
    private function caseAnAgentMustSettle(bool $withPaper = true): array
    {
        $user = $this->member(9_000_000);
        $store = app(ManualDocumentStore::class);

        $token = $store->token();
        $path = $store->stage($token, 'attestation_img', $this->attestation());

        $order = Order::query()->create([
            'user_id' => $user->id,
            'service_id' => Service::query()->firstOrCreate(
                ['slug' => self::SERVICE],
                ['name' => 'Nin Modification'],
            )->id,
            'customer_ref' => '12345678901',
            'amount' => self::CHARGE_KOBO,
            'profit' => 1_000_000,
            'provider' => 'manual',
            'provider_reference' => 'MANUAL-'.strtoupper(substr($token, 0, 8)),
            'status' => 'pending',
            'meta' => [
                'type' => 'manual_service',
                'manual_queue' => true,
                'manual_service' => self::SERVICE,
                'manual_service_title' => 'NIN Date of Birth Correction',
                'submitted' => ['nin' => '12345678901', 'field_to_modify' => 'dob'],
            ],
        ]);

        // The paper is out of reach for the passes below, then returns: a disk
        // that was busy on the pass that ran out of attempts is the ordinary way
        // a case is called lost while its contents are still on the shelf.
        Storage::disk('local')->delete($path);

        DocumentFiling::query()->create([
            'order_id' => $order->id,
            'token' => $token,
            'manifest' => [[
                'name' => 'attestation_img',
                'path' => $path,
                'original' => 'attestation.jpg',
                'mime' => 'image/jpeg',
            ]],
            'state' => DocumentFiling::AWAITING,
        ]);

        app(WalletLedger::class)->debit(
            Wallet::query()->where('user_id', $user->id)->firstOrFail(),
            self::CHARGE_KOBO,
            'MANUAL-'.$order->id,
            'NIN Date of Birth Correction',
        );

        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents');

        if ($withPaper) {
            Storage::disk('local')->put($path, 'the customer attestation');
        }

        $this->ageFolder($token);

        return [$order->fresh(), $token, $path];
    }

    private function ageFolder(string $token): void
    {
        $directory = Storage::disk('local')->path(self::HOLDING.'/'.$token);

        // Two hours past the sweep's own cutoff, so nothing about the timing of
        // the test can spare this folder: it is a candidate on every run.
        touch($directory, time() - (2 * 3600));
    }

    private function attestation(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('attestation.jpg', 'jpeg-bytes');
    }

    public function test_a_case_referred_to_a_person_still_names_its_own_folder_as_one_to_keep(): void
    {
        [$order, $token] = $this->caseAnAgentMustSettle();

        $filing = DocumentFiling::forOrder($order);
        $this->assertSame(DocumentFiling::LOST, $filing->state);

        // The protected list is what the sweep is handed, so a LOST case has to be
        // on it by itself and not only while some other order is waiting.
        $this->assertContains($token, DocumentFiling::heldTokens());
    }

    public function test_the_holding_sweep_spares_a_lost_case_and_only_a_lost_case_held_by_nobody_is_taken(): void
    {
        $store = app(ManualDocumentStore::class);

        [, $lostToken] = $this->caseAnAgentMustSettle();

        // Paper a dead request abandoned: no order paid for it and no agent is
        // working it, so the sweep is right to clear it.
        $orphan = $store->token();
        $store->stage($orphan, 'attestation_img', $this->attestation());
        $this->ageFolder($orphan);

        $store->pruneHolding(60, DocumentFiling::heldTokens());

        $disk = Storage::disk('local');
        $this->assertDirectoryExists($disk->path(self::HOLDING.'/'.$lostToken));
        $this->assertDirectoryDoesNotExist($disk->path(self::HOLDING.'/'.$orphan));
    }

    public function test_the_sweep_inside_an_ordinary_customer_submission_leaves_the_lost_case_untouched(): void
    {
        [$lostOrder, $lostToken, $lostPath] = $this->caseAnAgentMustSettle();

        $store = app(ManualDocumentStore::class);
        $orphan = $store->token();
        $store->stage($orphan, 'attestation_img', $this->attestation());
        $this->ageFolder($orphan);

        // A real customer paying for a real request: this is the production path
        // that runs the sweep, and it is the reason the danger is routine rather
        // than hypothetical - one submission a day is enough to empty the shelf.
        $payer = $this->member(9_000_000);

        $this->actingAs($payer)->post('/vtu/manual/'.self::SERVICE, [
            PurchaseIntent::FIELD => PurchaseIntent::mint(),
            'nin' => '23456789012',
            'email_choice' => 'auto',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849B',
            'attestation_img' => $this->attestation(),
            'consent_agreed' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->where('user_id', $payer->id)->count());

        $disk = Storage::disk('local');

        // The sweep ran - the orphan is gone - and the case an agent is working,
        // with its paper, came out the other side.
        $this->assertDirectoryDoesNotExist($disk->path(self::HOLDING.'/'.$orphan));
        $this->assertDirectoryExists($disk->path(self::HOLDING.'/'.$lostToken));
        $this->assertTrue($disk->exists($lostPath));
        $this->assertSame('the customer attestation', $disk->get($lostPath));
        $this->assertSame(DocumentFiling::LOST, DocumentFiling::forOrder($lostOrder)->state);
    }

    public function test_the_paper_a_lost_case_holds_stays_readable_to_the_agent_working_it(): void
    {
        [$order, $token, $path] = $this->caseAnAgentMustSettle();
        $admin = User::factory()->create(['is_admin' => true]);

        app(ManualDocumentStore::class)->pruneHolding(60, DocumentFiling::heldTokens());

        // The agent's view of the case still points at paper that is there: a
        // report that reads "not received" over a deleted folder is a report no
        // decision can be made from.
        $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id)
            ->assertOk()
            ->assertSee('The customer paid, and this document never reached the order', false);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertContains($token, DocumentFiling::heldTokens());
    }

    public function test_further_recovery_passes_neither_lose_the_case_again_nor_release_its_folder(): void
    {
        [$order, $token] = $this->caseAnAgentMustSettle();

        $before = WalletTransaction::query()->count();

        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents', ['--order' => $order->id]);

        $filing = DocumentFiling::forOrder($order);

        // A case already named to an agent is not re-opened, not counted again, and
        // not quietly turned into a refund by a scheduler.
        $this->assertSame(DocumentFiling::LOST, $filing->state);
        $this->assertSame(3, (int) $filing->attempts);
        $this->assertSame($before, WalletTransaction::query()->count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertContains($token, DocumentFiling::heldTokens());

        app(ManualDocumentStore::class)->pruneHolding(60, DocumentFiling::heldTokens());
        $this->assertDirectoryExists(Storage::disk('local')->path(self::HOLDING.'/'.$token));
    }

    public function test_a_customer_cannot_release_the_folder_of_their_own_lost_case(): void
    {
        [$order, $token] = $this->caseAnAgentMustSettle();
        $customer = $order->user;

        // The routes that close a case are an agent's, so the person whose money is
        // at stake has no way to settle it - or to make the evidence go away.
        $this->actingAs($customer)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => 'It was filed after all.',
        ])->assertForbidden();

        $this->assertSame(DocumentFiling::LOST, DocumentFiling::forOrder($order)->state);
        $this->assertContains($token, DocumentFiling::heldTokens());

        app(ManualDocumentStore::class)->pruneHolding(60, DocumentFiling::heldTokens());
        $this->assertDirectoryExists(Storage::disk('local')->path(self::HOLDING.'/'.$token));
    }

    public function test_an_agent_completing_the_request_is_what_hands_the_folder_back_to_the_sweep(): void
    {
        [$order, $token] = $this->caseAnAgentMustSettle();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/fulfil', [
            'result_text' => 'Date of birth corrected. New slip attached.',
        ])->assertRedirect();

        $filing = DocumentFiling::forOrder($order);
        $this->assertSame(DocumentFiling::RESOLVED, $filing->state);
        $this->assertStringContainsString('Settled by agent', (string) $filing->last_error);

        // The decision that ends the protection is a person's, and once it is made
        // the folder is an ordinary stale one.
        $this->assertNotContains($token, DocumentFiling::heldTokens());

        app(ManualDocumentStore::class)->pruneHolding(60, DocumentFiling::heldTokens());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path(self::HOLDING.'/'.$token));
    }

    public function test_an_agent_reversing_the_request_releases_the_folder_and_nothing_else_does(): void
    {
        [$order, $token] = $this->caseAnAgentMustSettle();
        $admin = User::factory()->create(['is_admin' => true]);

        // Refunding alone is not the act: the money comes back through the reversal
        // the agent is authorised to perform, and that is also what closes the case.
        $this->actingAs($admin)->post('/admin/manual-orders/'.$order->id.'/reject', [
            'reason' => 'The attestation could not be obtained, so the work cannot be done.',
        ])->assertRedirect();

        $filing = DocumentFiling::forOrder($order);
        $this->assertSame(DocumentFiling::ABANDONED, $filing->state);
        $this->assertNotContains($token, DocumentFiling::heldTokens());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(9_000_000, $this->balance($order->user));

        app(ManualDocumentStore::class)->pruneHolding(60, DocumentFiling::heldTokens());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path(self::HOLDING.'/'.$token));
    }

    private function member(int $kobo): User
    {
        $user = User::factory()->create();
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }
}
