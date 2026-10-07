<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Customers complain that money they sent never showed up. Most of that is not
 * missing money but missing attribution: the transfer landed in an account
 * number this site had already stopped advertising, so nothing any more asked
 * Flutterwave about it. These tests keep every account number a customer was
 * ever given answerable, and look for money even when the customer never opens
 * the page.
 */
class WalletSupersededDepositRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_ACCOUNT = '01234567890';
    private const SECOND_ACCOUNT = '01111111111';
    private const PERMANENT_ACCOUNT = '09876543210';
    private const REPLACEMENT_PERMANENT = '05555555555';

    /** @var list<array<string, mixed>> What GET /v3/transactions answers with. */
    private array $listCharges = [];

    /** @var array<string, array<string, mixed>> id or tx_ref => verify body. */
    private array $verifiableCharges = [];

    /** The account number the next virtual-account call hands out. */
    private ?string $issuedAccount = null;

    /** @var list<string> Every URL this test made Flutterwave answer. */
    private array $askedUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'test-secret-key',
            'services.flutterwave.secret_hash' => 'test-verif-hash',
        ]);

        // Registered once for the whole test: a later Http::fake() stacks behind
        // this one instead of replacing it.
        Http::fake(function (Request $request) {
            $this->askedUrls[] = $url = (string) $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_ends_with($path, '/v3/virtual-account-numbers')) {
                $permanent = (bool) ($request->data()['is_permanent'] ?? false);

                return Http::response(['status' => 'success', 'data' => [
                    'account_number' => $this->issuedAccount
                        ?: ($permanent ? self::PERMANENT_ACCOUNT : self::FIRST_ACCOUNT),
                    'bank_name' => 'Wema Bank',
                    'account_name' => 'TEST CUSTOMER',
                    'flw_ref' => 'FLW-VA-1',
                    'expires_at' => now()->addHours(6)->toIso8601String(),
                ]]);
            }

            if (preg_match('#^/v3/transactions/([^/]+)/verify$#', $path, $match)) {
                $charge = $this->verifiableCharges[urldecode($match[1])] ?? null;

                return $charge === null
                    ? Http::response(['status' => 'failed', 'message' => 'not found'], 404)
                    : Http::response(['status' => 'success', 'data' => $charge]);
            }

            if (str_ends_with($path, '/v3/transactions')) {
                return Http::response(['status' => 'success', 'data' => $this->listCharges]);
            }

            return Http::response(['status' => 'failed', 'message' => 'unexpected call'], 404);
        });
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        $user->email_verified_at = now();
        $user->save();

        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user->fresh();
    }

    /** A customer who was handed a dedicated account number and left it alone. */
    private function accountHolder(string $accountNumber): User
    {
        $user = $this->member();
        $user->forceFill([
            'virtual_account_number' => $accountNumber,
            'virtual_account_assigned_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function charge(array $overrides = []): array
    {
        return array_merge([
            'id' => 777,
            'tx_ref' => 'REF-1',
            'flw_ref' => 'FLW-MOCK-777',
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => 3000.00,
            'amount' => 3000.00,
            'payment_type' => 'bank_transfer',
            'account_number' => self::FIRST_ACCOUNT,
            'created_at' => now()->toIso8601String(),
            'customer' => ['email' => 'test@example.com'],
        ], $overrides);
    }

    private function oneTimeAccount(User $user, float $amount = 3000, ?string $account = null): WalletTransaction
    {
        $this->issuedAccount = $account;

        $this->actingAs($user)
            ->post('/wallet/virtual-account/temporary', ['temporary_amount' => $amount])
            ->assertSessionHasNoErrors();

        $this->issuedAccount = null;

        return WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->where('channel', 'flutterwave_virtual_account')
            ->latest('id')
            ->firstOrFail();
    }

    private function permanentAccount(User $user, string $bvn = '11223344556', ?string $account = null): void
    {
        $this->issuedAccount = $account;

        $this->actingAs($user)
            ->post('/wallet/virtual-account/assign', [
                'phone' => '08012345678',
                'identity_type' => 'bvn',
                'bvn' => $bvn,
            ])
            ->assertSessionHasNoErrors();

        $this->issuedAccount = null;
    }

    private function wasAskedAbout(string $token): bool
    {
        foreach ($this->askedUrls as $url) {
            if (str_contains($url, $token) || str_contains($url, rawurlencode($token))) {
                return true;
            }
        }

        return false;
    }

    public function test_a_permanent_account_does_not_erase_the_one_time_account_a_customer_is_paying_into(): void
    {
        $user = $this->member();

        $temporary = $this->oneTimeAccount($user);
        $this->permanentAccount($user);

        $metadata = (array) $user->fresh()->virtual_account_metadata;

        $this->assertSame(self::PERMANENT_ACCOUNT, $user->fresh()->virtual_account_number);
        $this->assertSame(
            $temporary->reference,
            $metadata['temporary_virtual_account']['tx_ref'] ?? null,
            'The one-time account the customer was told to pay is no longer on record.'
        );

        $this->askedUrls = [];
        $this->actingAs($user->fresh())->post('/wallet/deposits/check');

        $this->assertTrue(
            $this->wasAskedAbout((string) $temporary->reference),
            'The one-time account was never asked about, so money sent there stays invisible.'
        );
    }

    public function test_a_replaced_permanent_account_stays_askable(): void
    {
        $user = $this->member();

        $this->permanentAccount($user);
        $firstReference = (string) $user->fresh()->virtual_account_metadata['tx_ref'];

        // The customer changes the identity behind the account, so a second
        // dedicated number is issued over the same keys.
        $this->permanentAccount($user->fresh(), '99887766554', self::REPLACEMENT_PERMANENT);

        $previous = (array) ($user->fresh()->virtual_account_metadata['previous_virtual_accounts'] ?? []);

        $this->assertNotEmpty($previous, 'The account number the customer may already have paid was dropped.');
        $this->assertSame($firstReference, $previous[0]['tx_ref'] ?? null);
        $this->assertSame(self::PERMANENT_ACCOUNT, $previous[0]['account_number'] ?? null);

        $this->askedUrls = [];
        $this->actingAs($user->fresh())->post('/wallet/deposits/check');

        $this->assertTrue(
            $this->wasAskedAbout($firstReference),
            'The replaced account number is never asked about, so a transfer to it cannot be attributed.'
        );
    }

    public function test_a_transfer_into_a_superseded_one_time_account_is_still_credited(): void
    {
        $user = $this->member();

        $superseded = $this->oneTimeAccount($user);

        // Asking for a fresh one-time account writes the old one off, so the
        // deposit check can no longer treat it as an outstanding request.
        $replacement = $this->oneTimeAccount($user, 1200, self::SECOND_ACCOUNT);
        $this->assertSame('failed', $superseded->fresh()->status);

        // The transfer the customer actually made was to the first number.
        $this->listCharges = [['id' => 901, 'tx_ref' => $superseded->reference]];
        $this->verifiableCharges = [
            901 => $this->charge([
                'id' => 901,
                'tx_ref' => $superseded->reference,
                'flw_ref' => 'FLW-MOCK-901',
                'account_number' => self::FIRST_ACCOUNT,
            ]),
        ];

        $this->actingAs($user)
            ->post('/wallet/deposits/check')
            ->assertSessionHas('deposit_check.credited_kobo', 295_000);

        $this->assertSame(295_000, (int) $user->fresh()->wallet->balance);
        $this->assertSame(
            self::FIRST_ACCOUNT,
            (string) WalletTransaction::query()
                ->where('wallet_id', $user->wallet->id)
                ->where('status', 'success')
                ->latest('id')
                ->firstOrFail()->meta['virtual_account_number']
        );
        $this->assertSame('pending', $replacement->fresh()->status, 'The new request was paid for by the old money.');
    }

    public function test_the_scheduled_check_credits_a_customer_who_never_opens_their_page(): void
    {
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $this->listCharges = [['id' => 902, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [
            902 => $this->charge(['id' => 902, 'tx_ref' => $pending->reference, 'flw_ref' => 'FLW-MOCK-902']),
        ];

        $this->artisan('wallet:sync-deposits')->assertSuccessful();

        $this->assertSame(295_000, (int) $user->fresh()->wallet->balance);
        $this->assertSame('success', $pending->fresh()->status);
    }

    public function test_the_scheduled_check_walks_dedicated_accounts_a_little_further_every_run(): void
    {
        $this->accountHolder('07000000001');
        $this->accountHolder('07000000002');

        $this->askedUrls = [];
        $this->artisan('wallet:sync-deposits --limit=1')->assertSuccessful();
        $this->assertTrue($this->wasAskedAbout('07000000001'));
        $this->assertFalse($this->wasAskedAbout('07000000002'));

        $this->askedUrls = [];
        $this->artisan('wallet:sync-deposits --limit=1')->assertSuccessful();
        $this->assertTrue($this->wasAskedAbout('07000000002'));
        $this->assertFalse($this->wasAskedAbout('07000000001'));
    }
}
