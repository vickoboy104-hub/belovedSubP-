<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ManualDocumentStore;
use App\Services\ServiceFormRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A customer who sends a birth attestation with a date of birth correction is
 * paying for the work that paper makes possible. So the paper has to be provably
 * on our disk before their wallet is touched, and if it cannot be filed against
 * their order, the order must not stand and the charge must not stand with it.
 *
 * Every failure here is simulated after the bytes have really been written,
 * because the dangerous case is not "nothing happened" - it is "something
 * happened and the code was told it did not".
 */
class ManualUploadPaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ProbeDocumentStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');

        $this->store = new ProbeDocumentStore();
        $this->app->instance(ManualDocumentStore::class, $this->store);
    }

    /** The answers that make the provider demand a document. */
    private function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'nin' => '12345678901',
            'email_choice' => 'auto',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849A',
            'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg'),
            'consent_agreed' => '1',
        ], $overrides);
    }

    public function test_a_document_the_disk_cannot_keep_moves_no_money_and_books_no_order(): void
    {
        $user = $this->member(9_000_000);
        $this->store->holdReports = 'null';

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', $this->documentPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('attestation_img');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(9_000_000, $this->balance($user));
        $this->assertSame(0, \App\Models\WalletTransaction::query()->where('wallet_id', $user->wallet->id)->count());
    }

    public function test_a_disk_that_throws_while_a_document_is_being_held_moves_no_money(): void
    {
        $user = $this->member(9_000_000);
        $this->store->holdReports = 'throw';

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', $this->documentPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('attestation_img');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(9_000_000, $this->balance($user));
    }

    public function test_a_refused_submission_leaves_no_identity_document_lying_in_the_holding_area(): void
    {
        // The bytes really were written before the code was told they were not, so
        // this is the case where a photo of somebody's birth attestation would sit
        // on the server forever if the refusal did not sweep it.
        $user = $this->member(9_000_000);
        $this->store->holdReports = 'null';

        $this->actingAs($user)->post('/vtu/manual/nin_modification', $this->documentPayload());

        $this->assertSame([], $this->heldDocuments());
    }

    public function test_a_charged_request_whose_document_cannot_be_filed_is_closed_and_paid_back(): void
    {
        $user = $this->member(9_000_000);
        $this->store->fileReports = 'null';

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', $this->documentPayload())
            ->assertRedirect()
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'returned to your wallet');
            });

        $order = Order::query()->where('user_id', $user->id)->sole();

        // Not left in the queue as a paid job nobody can work.
        $this->assertSame('failed', $order->status);
        $this->assertTrue((bool) ($order->meta['documents_missing'] ?? false));
        $this->assertArrayNotHasKey('uploads', (array) $order->meta);

        $this->assertSame(9_000_000, $this->balance($user));
        $this->assertWalletIsWholeAgain($user);
    }

    public function test_a_charged_request_whose_filing_throws_is_closed_and_paid_back(): void
    {
        $user = $this->member(9_000_000);
        $this->store->fileReports = 'throw';

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', $this->documentPayload())
            ->assertRedirect();

        $order = Order::query()->where('user_id', $user->id)->sole();

        $this->assertSame('failed', $order->status);
        $this->assertSame(9_000_000, $this->balance($user));
        $this->assertWalletIsWholeAgain($user);

        // The copy that reached the order folder is taken back out, along with
        // whatever is still in the holding area.
        $this->assertSame([], Storage::disk('local')->files('manual-uploads/'.$order->id));
        $this->assertSame([], $this->heldDocuments());
    }

    public function test_a_customer_who_tries_again_after_a_failure_is_charged_once_and_held_to_it(): void
    {
        $user = $this->member(9_000_000);

        $this->store->fileReports = 'null';
        $this->actingAs($user)->post('/vtu/manual/nin_modification', $this->documentPayload())->assertRedirect();

        $this->store->fileReports = 'ok';
        $this->actingAs($user)->post('/vtu/manual/nin_modification', $this->documentPayload())->assertRedirect();

        $orders = Order::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame('failed', $orders[0]->status);
        $this->assertSame('pending', $orders[1]->status);

        // One charge, one refund, then the second charge: the wallet has paid for
        // the request that actually has its document and nothing else.
        $this->assertSame(9_000_000 - 4_000_000, $this->balance($user));

        $uploads = (array) ($orders[1]->meta['uploads'] ?? []);
        $this->assertArrayHasKey('attestation_img', $uploads);
        $this->assertTrue(Storage::disk('local')->exists((string) $uploads['attestation_img']['path']));
    }

    public function test_a_healthy_submission_still_charges_once_and_files_the_document(): void
    {
        $user = $this->member(9_000_000);

        $this->actingAs($user)
            ->post('/vtu/manual/nin_modification', $this->documentPayload())
            ->assertRedirect();

        $order = Order::query()->where('user_id', $user->id)->sole();

        $this->assertSame('pending', $order->status);
        $this->assertSame(4_000_000, (int) $order->amount);
        $this->assertSame(5_000_000, $this->balance($user));

        $upload = (array) ($order->meta['uploads']['attestation_img'] ?? []);
        $this->assertNotSame('', (string) ($upload['path'] ?? ''));
        $this->assertSame('attestation.jpg', $upload['name'] ?? null);
        $this->assertTrue(Storage::disk('local')->exists((string) $upload['path']));

        // The document is filed against the order that paid for it and is not
        // duplicated in the holding area.
        $this->assertStringStartsWith('manual-uploads/'.$order->id.'/', (string) $upload['path']);
        $this->assertSame([], $this->heldDocuments());
    }

    public function test_a_document_the_customer_offered_voluntarily_is_not_swallowed_into_a_paid_request(): void
    {
        // A service whose paper question is optional still must not take money
        // for a request whose attachment vanished: the customer would believe the
        // agent has something they do not.
        $slug = 'upload_probe';
        Setting::updateOrCreate(['key' => 'price_manual_'.$slug], ['value' => '1000']);
        settings_flush_cache();

        app(ServiceFormRepository::class)->persist($slug, [
            'title' => 'Upload Probe',
            'group' => 'nin',
            'summary' => 'A service that lets a document be attached without demanding one.',
            'fields' => [
                ['name' => 'nin', 'label' => 'NIN', 'type' => 'text', 'rules' => ['required', 'digits:11']],
                [
                    'name' => 'proof',
                    'label' => 'Attach anything that helps',
                    'type' => 'file',
                    'rules' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
                ],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            ],
        ]);

        $user = $this->member(500_000);
        $this->store->holdReports = 'null';

        $this->actingAs($user)
            ->post('/vtu/manual/'.$slug, [
                'nin' => '12345678901',
                'proof' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
                'consent_agreed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('proof');

        $this->assertSame(0, Order::query()->where('user_id', $user->id)->count());
        $this->assertSame(500_000, $this->balance($user));
        $this->assertSame([], $this->heldDocuments());

        app(ServiceFormRepository::class)->delete($slug);
    }

    /**
     * Every document still sitting in the temporary holding area, whatever the
     * submission was told about it.
     *
     * @return list<string>
     */
    private function heldDocuments(): array
    {
        $disk = Storage::disk('local');
        $found = [];

        foreach ($disk->directories('manual-uploads/.pending') as $directory) {
            $found = array_merge($found, $disk->files($directory));
        }

        return $found;
    }

    /**
     * A refund is only a refund if the ledger says so twice: the money out and the
     * money back, against the same order reference.
     */
    private function assertWalletIsWholeAgain(User $user): void
    {
        $transactions = \App\Models\WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertSame('debit', $transactions[0]->type);
        $this->assertSame('credit', $transactions[1]->type);
        $this->assertSame((int) $transactions[0]->amount, (int) $transactions[1]->amount);
        $this->assertStringStartsWith('REFUND-', (string) $transactions[1]->reference);
    }

    private function member(int $kobo): User
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

/**
 * The real document store, told to lie about whether the disk kept the file -
 * after it has genuinely written it, which is the only failure worth testing.
 */
class ProbeDocumentStore extends ManualDocumentStore
{
    public string $holdReports = 'ok';

    public string $fileReports = 'ok';

    public function stage(string $token, string $field, UploadedFile $file): ?string
    {
        $path = parent::stage($token, $field, $file);

        if ($this->holdReports === 'throw') {
            throw new \RuntimeException('the disk refused');
        }

        return $this->holdReports === 'null' ? null : $path;
    }

    public function publish(string $token, string $path, int $orderId): ?string
    {
        $target = parent::publish($token, $path, $orderId);

        if ($this->fileReports === 'throw') {
            throw new \RuntimeException('the move broke');
        }

        return $this->fileReports === 'null' ? null : $target;
    }
}
