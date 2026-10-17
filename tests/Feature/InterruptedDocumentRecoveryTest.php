<?php

namespace Tests\Feature;

use App\Models\DocumentFiling;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\AdminSystemAlertNotification;
use App\Services\ManualDocumentStore;
use App\Services\PurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The worst shape on the money path is the one nobody can see: the customer has
 * paid, the order is on the queue, and the document that makes the job possible is
 * sitting in a holding folder that only the finished request knew about. Nothing
 * in the database said the paper existed, so nothing went back for it, and the
 * sweep that clears old folders was free to destroy it.
 *
 * These tests hold the line that a paid request always ends up holding its own
 * documents: the record of what is being kept is written with the money, an
 * interrupted filing is finished by a run that cannot charge or refund anybody,
 * and paper that has genuinely gone is put in front of a person instead of being
 * quietly turned into a refund.
 */
class InterruptedDocumentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = 'nin_modification';

    private const CHARGE_KOBO = 4_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Notification::fake();
    }

    /**
     * The state a committed request leaves behind: money taken, order on the
     * queue, paper held in a folder, and the filing closed only by the request
     * that never came back.
     *
     * @return array{0: Order, 1: string}
     */
    private function paidButInterrupted(
        ?User $user = null,
        bool $paperReallyThere = true,
        bool $alreadyAtTheOrderFolder = false,
    ): array {
        $user ??= $this->member(9_000_000);
        $store = app(ManualDocumentStore::class);

        $token = $store->token();
        $path = $store->stage($token, 'attestation_img', $this->attestation());

        if (!$paperReallyThere) {
            Storage::disk('local')->delete($path);
        }

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

        if ($alreadyAtTheOrderFolder) {
            // The move landed and the write after it never did.
            Storage::disk('local')->move($path, 'manual-uploads/'.$order->id.'/'.basename((string) $path));
        }

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

        // The debit the request made when it committed the order.
        app(\App\Services\WalletLedger::class)->debit(
            Wallet::query()->where('user_id', $user->id)->firstOrFail(),
            self::CHARGE_KOBO,
            'MANUAL-'.$order->id,
            'NIN Date of Birth Correction',
        );

        return [$order->fresh(), $token];
    }

    private function attestation(): UploadedFile
    {
        return UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg');
    }

    public function test_the_record_of_held_paper_exists_before_the_first_document_is_moved(): void
    {
        $user = $this->member(9_000_000);

        $witness = new WitnessDocumentStore();
        $this->app->instance(ManualDocumentStore::class, $witness);

        $this->actingAs($user)
            ->post('/vtu/manual/'.self::SERVICE, [
                PurchaseIntent::FIELD => PurchaseIntent::mint(),
                'nin' => '12345678901',
                'email_choice' => 'auto',
                'field_to_modify' => 'dob',
                'attestation_no' => 'SO1250325358849A',
                'attestation_img' => $this->attestation(),
                'consent_agreed' => '1',
            ])
            ->assertRedirect();

        // Proved from inside the request: at the moment the first byte was being
        // moved, the database already said which order this paper belongs to and
        // what is in the folder. A process that died there would have left a
        // recoverable record, not a mystery.
        $this->assertTrue($witness->foundOpenRecord,
            'The document was moved before any durable record said it had to be.');
        $this->assertSame(1, $witness->manifestSize);
    }

    public function test_a_paid_request_that_never_finished_filing_is_finished_by_a_run(): void
    {
        [$order, $token] = $this->paidButInterrupted();
        $held = Storage::disk('local')->path('manual-uploads/.pending/'.$token);

        $this->assertFileExists($held);
        $this->assertArrayNotHasKey('uploads', (array) $order->meta);

        Artisan::call('manual:recover-documents');

        $order->refresh();
        $upload = (array) ($order->meta['uploads']['attestation_img'] ?? []);

        $this->assertNotSame('', (string) ($upload['path'] ?? ''));
        $this->assertStringStartsWith('manual-uploads/'.$order->id.'/', (string) $upload['path']);
        $this->assertSame('attestation.jpg', $upload['name'] ?? null);
        $this->assertTrue(Storage::disk('local')->exists((string) $upload['path']));

        $this->assertSame(DocumentFiling::FILED, DocumentFiling::forOrder($order)->state);
        $this->assertFileDoesNotExist($held);

        // The job is still the job it was paid for.
        $this->assertSame('pending', $order->status);
    }

    public function test_paper_that_was_moved_but_never_written_onto_the_order_is_taken_back(): void
    {
        // Moving a file is a disk action and recording it is a database action, so
        // there is a real moment where the customer's attestation sits in the
        // order's own folder while the order still says it has nothing. A recovery
        // that only searched the holding area would call that paper lost and set an
        // agent to chase a document that is already on the disk.
        [$order, $token] = $this->paidButInterrupted(alreadyAtTheOrderFolder: true);

        Artisan::call('manual:recover-documents');

        $order->refresh();
        $upload = (array) ($order->meta['uploads']['attestation_img'] ?? []);
        $files = Storage::disk('local')->files('manual-uploads/'.$order->id);

        $this->assertCount(1, $files);
        $this->assertSame($files[0], $upload['path'] ?? null);
        $this->assertTrue(Storage::disk('local')->exists((string) ($upload['path'] ?? '')));
        $this->assertSame('attestation.jpg', $upload['name'] ?? null);

        $this->assertSame(DocumentFiling::FILED, DocumentFiling::forOrder($order)->state);
        $this->assertFileDoesNotExist(Storage::disk('local')->path('manual-uploads/.pending/'.$token));

        // The same charge, and no refund invented along the way.
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame(9_000_000 - self::CHARGE_KOBO, $this->balance($order->user));
    }

    public function test_running_the_recovery_again_files_and_books_nothing(): void
    {
        [$order] = $this->paidButInterrupted();

        Artisan::call('manual:recover-documents');

        $movements = WalletTransaction::query()->count();
        $files = Storage::disk('local')->files('manual-uploads/'.$order->id);

        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents');

        $order->refresh();

        $this->assertCount(1, $files);
        $this->assertSame($files, Storage::disk('local')->files('manual-uploads/'.$order->id));
        $this->assertSame($movements, WalletTransaction::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(DocumentFiling::FILED, DocumentFiling::forOrder($order)->state);
        $this->assertSame([], (array) DocumentFiling::forOrder($order)->manifest);
    }

    public function test_paper_that_has_actually_gone_is_taken_to_an_admin_instead_of_refunded(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        [$order] = $this->paidButInterrupted(paperReallyThere: false);
        $before = $this->balance($order->user);

        // Three passes, because a disk that was merely busy has to be given the
        // chance to come back before anything is called lost.
        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents');
        Artisan::call('manual:recover-documents');

        $filing = DocumentFiling::forOrder($order);

        $this->assertSame(DocumentFiling::LOST, $filing->state);
        $this->assertStringContainsString('attestation_img', (string) $filing->last_error);

        // No money moves in either direction without a person deciding it.
        $this->assertSame($before, $this->balance($order->user));
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertArrayNotHasKey('uploads', (array) $order->fresh()->meta);

        Notification::assertSentTo($admin, AdminSystemAlertNotification::class);

        // And the same news is not told a second time.
        Notification::fake();
        Artisan::call('manual:recover-documents');
        Notification::assertNothingSent();
    }

    public function test_a_request_that_was_paid_back_is_never_given_paper_after_the_fact(): void
    {
        [$order] = $this->paidButInterrupted();

        $order->forceFill(['status' => 'failed'])->save();

        Artisan::call('manual:recover-documents');

        $this->assertSame(DocumentFiling::ABANDONED, DocumentFiling::forOrder($order)->state);
        $this->assertArrayNotHasKey('uploads', (array) $order->fresh()->meta);
        $this->assertSame([], Storage::disk('local')->files('manual-uploads/'.$order->id));
        $this->assertSame(1, WalletTransaction::query()->count());
    }

    public function test_the_holding_sweep_leaves_alone_the_folder_a_paid_order_still_needs(): void
    {
        $store = app(ManualDocumentStore::class);
        $disk = Storage::disk('local');

        [, $neededToken] = $this->paidButInterrupted();

        // Paper a dead request abandoned: nobody paid for it, so it may go.
        $orphan = $store->token();
        $store->stage($orphan, 'attestation_img', $this->attestation());

        // A sweep told to clear anything an hour past its own cutoff: every folder
        // in the holding area is now a candidate, so the only thing that can save
        // one of them is a paid order still waiting on it.
        $store->pruneHolding(-60, DocumentFiling::heldTokens());

        $this->assertFileExists($disk->path('manual-uploads/.pending/'.$neededToken));
        $this->assertFileDoesNotExist($disk->path('manual-uploads/.pending/'.$orphan));

        // And once the recovery has filed it, the same sweep is allowed to clear it.
        Artisan::call('manual:recover-documents');
        $store->pruneHolding(-60, DocumentFiling::heldTokens());

        $this->assertFileDoesNotExist($disk->path('manual-uploads/.pending/'.$neededToken));
    }

    public function test_an_agent_is_shown_which_paid_requests_are_short_of_their_paper(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$order] = $this->paidButInterrupted();

        $this->actingAs($admin)->get('/admin/manual-orders/'.$order->id)
            ->assertOk()
            ->assertSee('Paid, and the document is still being attached', false);

        $this->actingAs($admin)->get('/admin/manual-orders')
            ->assertOk()
            ->assertSee('Paper not attached', false);

        // The filter is the way to the one request, and only to it.
        $other = $this->paidButInterrupted();
        Artisan::call('manual:recover-documents', ['--order' => $other[0]->id]);

        $html = $this->actingAs($admin)->get('/admin/manual-orders?documents=short')
            ->assertOk()
            ->getContent() ?? '';

        $this->assertStringContainsString('/admin/manual-orders/'.$order->id, $html);
        $this->assertStringNotContainsString('/admin/manual-orders/'.$other[0]->id, $html);
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

/**
 * The real store, asked to report what the database held at the instant it was
 * told to move the first document.
 */
class WitnessDocumentStore extends ManualDocumentStore
{
    public bool $foundOpenRecord = false;

    public int $manifestSize = 0;

    public function publish(string $token, string $path, int $orderId): ?string
    {
        $open = DocumentFiling::query()
            ->where('order_id', $orderId)
            ->where('state', DocumentFiling::AWAITING)
            ->first();

        $this->foundOpenRecord = $open !== null;
        $this->manifestSize = count((array) ($open?->manifest ?? []));

        return parent::publish($token, $path, $orderId);
    }
}
