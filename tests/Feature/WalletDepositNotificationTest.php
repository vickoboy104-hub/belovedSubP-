<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use App\Services\WalletFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The owner paid into a one-time account and a permanent account and saw nothing
 * arrive. Their money had reached Flutterwave; Flutterwave simply could not tell
 * a machine it cannot reach about it. These tests cover the other half of the
 * story: the site asks Flutterwave directly, and every arrival is confirmed to
 * the customer on their dashboard and by email.
 */
class WalletDepositNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_NUMBER = '01234567890';

    /** What GET /v3/transactions answers with. */
    private array $listCharges = [];

    /** id or tx_ref => charge body, answered by the verify endpoint. */
    private array $verifiableCharges = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'test-secret-key',
            'services.flutterwave.secret_hash' => 'test-verif-hash',
        ]);
    }

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ], $attributes));

        $user->email_verified_at = now();
        $user->save();

        // The observer already opened a zero-balance wallet; say so rather than
        // giving this customer two of them.
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $charge
     */
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
            'account_number' => self::ACCOUNT_NUMBER,
            'created_at' => now()->toIso8601String(),
            'customer' => ['email' => 'test@example.com'],
        ], $overrides);
    }

    private function fakeFlutterwave(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/v3/virtual-account-numbers')) {
                return Http::response(['status' => 'success', 'data' => [
                    'account_number' => self::ACCOUNT_NUMBER,
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

    private function oneTimeAccount(User $user): WalletTransaction
    {
        $this->fakeFlutterwave();

        $this->actingAs($user)
            ->post('/wallet/virtual-account/temporary', ['temporary_amount' => 3000])
            ->assertSessionHasNoErrors();

        return WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->where('channel', 'flutterwave_virtual_account')
            ->latest('id')
            ->firstOrFail();
    }

    public function test_generating_a_one_time_account_records_the_transfer_it_waits_for(): void
    {
        $user = $this->member();

        $pending = $this->oneTimeAccount($user);

        $this->assertSame('pending', $pending->status);
        $this->assertSame(300_000, (int) $pending->amount);
        $this->assertSame(295_000, (int) $pending->meta['credited_kobo']);
        $this->assertStringContainsString(self::ACCOUNT_NUMBER, $pending->description);

        // The customer is told what is outstanding before they ask about it.
        $this->actingAs($user)->get('/wallet/fund')
            ->assertOk()
            ->assertSee('Awaiting')
            ->assertSee('Deposit status');
    }

    public function test_a_transfer_is_credited_by_asking_flutterwave_directly(): void
    {
        Mail::spy();
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [777 => $this->charge(['tx_ref' => $pending->reference])];

        $this->actingAs($user)
            ->post('/wallet/deposits/check')
            ->assertSessionHas('deposit_check.status', 'credited');

        $this->assertSame(295_000, (int) $user->wallet->fresh()->balance);

        $arrived = $pending->fresh();
        $this->assertSame('success', $arrived->status);
        $this->assertSame(777, (int) $arrived->meta['flutterwave_charge_id']);
        $this->assertSame('deposit_check', $arrived->meta['arrived_via']);
    }

    public function test_every_payment_is_shown_on_the_dashboard_and_emailed(): void
    {
        Mail::spy();
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [777 => $this->charge(['tx_ref' => $pending->reference])];

        $this->actingAs($user)->post('/wallet/deposits/check');

        Mail::assertSent(UserWalletActivityNotification::class, function (UserWalletActivityNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'Payment received')
                && str_contains($mail->subject, '2,950.00')
                && str_contains($mail->greeting, 'Test');
        });

        $this->assertSame(1, $user->notifications()->count());

        $alert = $user->notifications()->first()->data;
        $this->assertSame('credit', $alert['type']);
        $this->assertSame(295_000, (int) $alert['amount_kobo']);
        $this->assertSame('/wallet/transactions', parse_url((string) $alert['url'], PHP_URL_PATH));

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Payment received')
            ->assertSee('2,950.00');
    }

    public function test_a_webhook_and_a_later_check_can_never_pay_the_same_transfer_twice(): void
    {
        Mail::spy();
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $charge = $this->charge(['tx_ref' => $pending->reference]);
        $this->verifiableCharges = [777 => $charge];
        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];

        // Flutterwave reaches the site once, then the customer presses the button.
        $this->postJson('/wallet/flutterwave/webhook-v2', [
            'event' => 'charge.completed',
            'event.type' => 'BANK_TRANSFER_TRANSACTION',
            'data' => ['id' => 777, 'status' => 'successful', 'tx_ref' => $pending->reference],
            'meta_data' => [],
        ], ['verif-hash' => 'test-verif-hash'])->assertOk();

        $this->assertSame(295_000, (int) $user->wallet->fresh()->balance);

        $this->actingAs($user)->post('/wallet/deposits/check');

        $this->assertSame(295_000, (int) $user->wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()->where('status', 'success')->count());

        // One arrival is one alert, not two.
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_a_transfer_belonging_to_somebody_else_is_not_credited(): void
    {
        Mail::spy();
        $user = $this->member();
        $this->oneTimeAccount($user);

        // Flutterwave answers our reference with a charge that is demonstrably
        // not ours: another reference and another account number.
        $this->listCharges = [$this->charge([
            'id' => 888,
            'tx_ref' => 'FLW_TMP_SOMEBODYELSE',
            'account_number' => '09999999999',
        ])];
        $this->verifiableCharges = [888 => $this->charge([
            'id' => 888,
            'tx_ref' => 'FLW_TMP_SOMEBODYELSE',
            'account_number' => '09999999999',
        ])];

        $this->actingAs($user)
            ->post('/wallet/deposits/check')
            ->assertSessionHas('deposit_check.status', 'no_deposit_found');

        $this->assertSame(0, (int) $user->wallet->fresh()->balance);
        Mail::assertNothingSent();
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_a_short_transfer_is_not_credited_as_the_amount_requested(): void
    {
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $charge = $this->charge(['tx_ref' => $pending->reference, 'charged_amount' => 2500.00, 'amount' => 2500.00]);
        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [777 => $charge];

        $this->actingAs($user)->post('/wallet/deposits/check');

        // Underpaid by the bank: nothing is guessed at, and the money is left
        // visible as outstanding for support to trace.
        $this->assertSame(0, (int) $user->wallet->fresh()->balance);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_a_larger_transfer_is_credited_on_what_actually_landed(): void
    {
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $charge = $this->charge(['tx_ref' => $pending->reference, 'charged_amount' => 3500.00, 'amount' => 3500.00]);
        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [777 => $charge];

        $this->actingAs($user)->post('/wallet/deposits/check');

        $this->assertSame(345_000, (int) $user->wallet->fresh()->balance);
        $this->assertSame(350_000, (int) $pending->fresh()->amount);
    }

    public function test_the_fund_page_keeps_the_customer_informed_while_a_transfer_is_coming(): void
    {
        $user = $this->member();
        $this->oneTimeAccount($user);

        $this->fakeFlutterwave();
        $this->listCharges = [];
        $this->verifiableCharges = [];

        $this->actingAs($user)->get('/wallet/fund')
            ->assertOk()
            ->assertSee('Still waiting for your transfer')
            ->assertSee('Check deposit');
    }

    public function test_an_unconfigured_payment_key_tells_the_customer_not_to_send_money(): void
    {
        config(['services.flutterwave.secret_key' => '']);

        $user = $this->member();
        WalletTransaction::query()->create([
            'wallet_id' => $user->wallet->id,
            'type' => 'credit',
            'amount' => 300_000,
            'reference' => 'FLW_TMP_PENDING',
            'status' => 'pending',
            'channel' => 'flutterwave_virtual_account',
            'description' => 'Waiting for a bank transfer',
            'meta' => [],
        ]);

        $this->actingAs($user)->get('/wallet/fund')
            ->assertOk()
            ->assertSee('Card and transfer deposits are switched off');
    }

    public function test_a_charge_with_no_matching_request_creates_its_own_ledger_row(): void
    {
        $user = $this->member();
        $this->oneTimeAccount($user);

        // A transfer into the permanent account with a reference we never wrote
        // down still has to be banked, once, as its own record.
        $stranger = $this->charge(['id' => 999, 'tx_ref' => 'FLW_VA_UNKNOWN', 'charged_amount' => 5000.00, 'amount' => 5000.00]);

        app(WalletFundingService::class)->creditFlutterwaveCharge(
            $user->wallet,
            $stranger,
            'flutterwave_virtual_account',
            'webhook',
        );

        $row = WalletTransaction::query()->where('meta->tx_ref', 'FLW_VA_UNKNOWN')->sole();
        $this->assertSame(500_000, (int) $row->amount);
        $this->assertSame(495_000, (int) $row->meta['credited_kobo']);
        $this->assertSame(495_000, (int) $user->wallet->fresh()->balance);

        app(WalletFundingService::class)->creditFlutterwaveCharge(
            $user->wallet,
            $stranger,
            'flutterwave_virtual_account',
            'webhook',
        );

        $this->assertSame(495_000, (int) $user->wallet->fresh()->balance);
    }

    public function test_checks_are_not_allowed_to_batter_flutterwave(): void
    {
        $user = $this->member();
        $pending = $this->oneTimeAccount($user);

        $this->listCharges = [['id' => 777, 'tx_ref' => $pending->reference]];
        $this->verifiableCharges = [777 => $this->charge(['tx_ref' => $pending->reference])];

        $this->actingAs($user)->post('/wallet/deposits/check');
        $callsAfterFirst = count(Http::recorded());

        $this->actingAs($user)->post('/wallet/deposits/check');

        $this->assertCount($callsAfterFirst, Http::recorded());
        $this->assertSame(295_000, (int) $user->wallet->fresh()->balance);
    }

    public function test_a_second_transfer_into_the_same_permanent_account_is_credited(): void
    {
        Mail::spy();
        $funding = app(WalletFundingService::class);

        // A permanent account is issued once and paid into many times, and every
        // transfer that lands in it carries the one reference the account was
        // created under. Keying deposits on that reference would mean the second
        // customer payment ever is mistaken for a replay of the first.
        $user = $this->member([
            'virtual_account_number' => self::ACCOUNT_NUMBER,
            'virtual_account_metadata' => ['tx_ref' => 'FLW_VA_PERMANENT'],
        ]);

        $first = $funding->creditFlutterwaveCharge(
            $user->wallet,
            $this->charge(['id' => 777, 'tx_ref' => 'FLW_VA_PERMANENT', 'flw_ref' => 'FLW-MOCK-777']),
            'flutterwave_virtual_account',
            'deposit_check',
        );

        $this->assertSame(295_000, $first);

        $second = $funding->creditFlutterwaveCharge(
            $user->fresh()->wallet,
            $this->charge(['id' => 888, 'tx_ref' => 'FLW_VA_PERMANENT', 'flw_ref' => 'FLW-MOCK-888', 'charged_amount' => 1500.00, 'amount' => 1500.00]),
            'flutterwave_virtual_account',
            'deposit_check',
        );

        $this->assertSame(145_000, $second, 'a fresh transfer into a permanent account is not a duplicate');
        $this->assertSame(440_000, (int) $user->fresh()->wallet->balance);
        $this->assertSame(2, WalletTransaction::query()->where('meta->tx_ref', 'FLW_VA_PERMANENT')->count());
        Mail::assertSent(UserWalletActivityNotification::class, 2);
    }

    public function test_the_same_transfer_reported_twice_is_still_credited_once(): void
    {
        Mail::spy();
        $funding = app(WalletFundingService::class);
        $user = $this->member([
            'virtual_account_number' => self::ACCOUNT_NUMBER,
            'virtual_account_metadata' => ['tx_ref' => 'FLW_VA_PERMANENT'],
        ]);

        $charge = $this->charge(['id' => 777, 'tx_ref' => 'FLW_VA_PERMANENT']);

        $this->assertSame(295_000, $funding->creditFlutterwaveCharge($user->wallet, $charge, 'flutterwave_virtual_account', 'webhook'));
        $this->assertSame(0, $funding->creditFlutterwaveCharge($user->fresh()->wallet, $charge, 'flutterwave_virtual_account', 'deposit_check'));
        $this->assertSame(295_000, (int) $user->fresh()->wallet->balance);
    }

    public function test_a_wallet_with_nothing_outstanding_stops_advertising_a_waiting_transfer(): void
    {
        $user = $this->member();
        $stale = $this->oneTimeAccount($user);
        $stale->forceFill(['created_at' => now()->subHours(3)])->save();

        // Flutterwave was asked and answered: no transfer under that reference.
        $this->fakeFlutterwave();
        $this->listCharges = [];
        $this->verifiableCharges = [];

        $this->actingAs($user)
            ->post('/wallet/deposits/check')
            ->assertSessionHas('deposit_check.status', 'settled');

        $this->assertSame('failed', $stale->fresh()->status);
        $this->actingAs($user)->get('/wallet/transactions')
            ->assertOk()
            ->assertSee('Nothing is waiting on your account')
            ->assertDontSee('Still waiting for your transfer');
    }

    public function test_a_transfer_that_lands_after_its_request_was_closed_out_is_still_credited(): void
    {
        $user = $this->member();
        $stale = $this->oneTimeAccount($user);
        $stale->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->fakeFlutterwave();
        $this->listCharges = [];
        $this->verifiableCharges = [];
        $this->actingAs($user)->post('/wallet/deposits/check');

        $this->assertSame('failed', $stale->fresh()->status);

        // The bank took its time and the money arrives anyway, short of the
        // amount the abandoned request had asked for. Closing that request out
        // must never be a reason to refuse it.
        $charge = $this->charge([
            'id' => 888,
            'tx_ref' => $stale->reference,
            'flw_ref' => 'FLW-MOCK-888',
            'charged_amount' => 100.00,
            'amount' => 100.00,
        ]);

        app(WalletFundingService::class)->creditFlutterwaveCharge(
            $user->fresh()->wallet,
            $charge,
            'flutterwave_virtual_account',
            'webhook',
        );

        $this->assertSame(5_000, (int) $user->fresh()->wallet->balance);
    }

    public function test_the_check_button_is_not_blocked_by_the_page_having_just_loaded(): void
    {
        $user = $this->member();
        $this->oneTimeAccount($user);

        $this->fakeFlutterwave();
        $this->listCharges = [];
        $this->verifiableCharges = [];

        // Opening the page asks quietly for its own account; the customer's own
        // press must still be allowed to run immediately afterwards.
        $this->actingAs($user)->get('/wallet/fund')->assertOk();

        $this->actingAs($user)
            ->post('/wallet/deposits/check')
            ->assertSessionHas('deposit_check.status', 'no_deposit_found')
            ->assertSessionHas('deposit_check.checked', 1);
    }

    public function test_only_the_customer_who_was_given_the_account_is_paid(): void
    {
        Mail::spy();

        $owner = $this->member();
        $other = $this->member();

        $ownersRequest = $this->oneTimeAccount($owner);
        $othersRequest = $this->oneTimeAccount($other);

        $this->listCharges = [];
        $this->verifiableCharges = [777 => $this->charge([
            'id' => 777,
            'tx_ref' => $ownersRequest->reference,
            'customer' => ['email' => $owner->email],
        ])];

        $this->postJson('/wallet/flutterwave/webhook-v2', [
            'event' => 'charge.completed',
            'event.type' => 'BANK_TRANSFER_TRANSACTION',
            'data' => ['id' => 777, 'status' => 'successful', 'tx_ref' => $ownersRequest->reference],
            'meta_data' => [],
        ], ['verif-hash' => 'test-verif-hash'])->assertOk();

        $this->assertSame(295_000, (int) $owner->wallet->fresh()->balance);
        $this->assertSame(0, (int) $other->wallet->fresh()->balance);
        $this->assertSame('pending', $othersRequest->fresh()->status);
        $this->assertSame(0, $other->notifications()->count());
        Mail::assertSent(UserWalletActivityNotification::class, 1);
    }
}
