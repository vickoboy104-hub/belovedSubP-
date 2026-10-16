<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Money entering the wallet is the one place a customer can be told too late what
 * the money is for, so the agreement is put in front of the funding form and the
 * server holds them to it.
 */
class WalletFundingAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_agreement_stands_in_front_of_the_funding_form(): void
    {
        $user = $this->memberWithBalance(0);

        $page = $this->actingAs($user)->get('/wallet/fund')->assertOk()->getContent();

        $this->assertStringContainsString('data-consent-gate', $page);
        $this->assertStringContainsString('data-consent-terms', $page);
        $this->assertStringContainsString('data-consent-form', $page);
        $this->assertStringContainsString('Service agreement', $page);
        $this->assertStringContainsString('I agree, show me the funding options', $page);
        $this->assertStringContainsString('not a bank', $page);

        // The address a deposit can land on is the account's own, so it is shown
        // as a fact rather than offered as a field to type into.
        $this->assertStringContainsString('value="'.e($user->email, false).'"', $page);
        $this->assertStringContainsString('disabled', $page);
    }

    public function test_a_deposit_is_refused_when_the_agreement_has_not_been_accepted(): void
    {
        $user = $this->memberWithBalance(0);
        $this->configureFlutterwave();

        $this->actingAs($user)
            ->post('/wallet/fund', ['amount' => 2000])
            ->assertSessionHasErrors('consent_agreed');

        $this->assertSame(0, WalletTransaction::query()->where('wallet_id', $user->wallet->id)->count());
    }

    public function test_accepting_the_agreement_is_recorded_on_the_deposit(): void
    {
        $user = $this->memberWithBalance(0);
        $this->configureFlutterwave();

        Http::fake([
            'flutter.test/*' => Http::response([
                'status' => 'success',
                'message' => 'Container initialized for payment',
                'data' => ['link' => 'https://flutter.test/checkout/abc'],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post('/wallet/fund', ['amount' => 2000, 'consent_agreed' => '1'])
            ->assertRedirect('https://flutter.test/checkout/abc');

        $tx = WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($tx->meta['consent_agreed']);
    }

    private function configureFlutterwave(): void
    {
        config([
            'services.flutterwave.base_url' => 'https://flutter.test',
            'services.flutterwave.secret_key' => 'test-secret-not-a-real-key',
        ]);
    }

    /**
     * The customer comes back from the card page and this site asks Flutterwave
     * what actually happened before anything is banked - once, and at the amount
     * that really arrived, minus the fee the agreement in front of the form said.
     */
    public function test_coming_back_from_the_checkout_credits_the_deposit_once_at_the_agreed_price(): void
    {
        $user = $this->memberWithBalance(0);
        $this->configureFlutterwave();
        Notification::fake();

        $charged = [
            'id' => 9001,
            'flw_ref' => 'FLW-CHECKOUT-9001',
            'tx_ref' => null,
            'status' => 'successful',
            'currency' => 'NGN',
            'charged_amount' => 2000.00,
            'amount' => 2000.00,
            'customer' => ['email' => $user->email],
        ];

        Http::fake(function ($request) use (&$charged, $user) {
            if (str_contains((string) $request->url(), '/verify')) {
                return Http::response(['status' => 'success', 'data' => $charged]);
            }

            return Http::response([
                'status' => 'success',
                'message' => 'Container initialized for payment',
                'data' => ['link' => 'https://flutter.test/checkout/abc'],
            ]);
        });

        $this->actingAs($user)
            ->post('/wallet/fund', ['amount' => 2000, 'consent_agreed' => '1'])
            ->assertRedirect('https://flutter.test/checkout/abc');

        $tx = WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->latest('id')
            ->firstOrFail();
        $charged['tx_ref'] = $tx->reference;

        $this->assertSame('pending', $tx->status);
        $this->assertSame(0, (int) $user->fresh()->wallet->balance, 'A deposit was banked before it arrived.');

        $first = $this->actingAs($user)->get('/wallet/flutterwave/callback?'
            .http_build_query(['tx_ref' => $tx->reference, 'transaction_id' => '9001', 'status' => 'successful']));

        $first->assertRedirect(route('wallet.fund'));
        $first->assertSessionHas('success', 'Wallet funded successfully (N50.00 fee deducted)');
        $this->assertSame(195_000, (int) $user->fresh()->wallet->balance);
        $this->assertSame('success', WalletTransaction::query()->where('id', $tx->id)->value('status'));

        // Flutterwave's own retry, or the customer pressing Back and arriving again:
        // the second visit is refused by the charge claim, not by a friendly message.
        $second = $this->actingAs($user)->get('/wallet/flutterwave/callback?'
            .http_build_query(['tx_ref' => $tx->reference, 'transaction_id' => '9001', 'status' => 'successful']));

        $second->assertSessionHas('success', 'Wallet already funded.');
        $this->assertSame(195_000, (int) $user->fresh()->wallet->balance, 'The same card payment funded the wallet twice.');
        $this->assertSame(
            1,
            WalletTransaction::query()->where('wallet_id', $user->wallet->id)->where('status', 'success')->count()
        );
        $this->assertSame(
            2,
            DB::table('flutterwave_charge_claims')
                ->where('wallet_id', $user->wallet->id)
                ->whereIn('charge_token', ['9001', 'FLW-CHECKOUT-9001'])
                ->count(),
            'The checkout leg banked money without claiming the charge.'
        );
    }

    /**
     * A return that reports a payment this site cannot verify must never be a way in:
     * nothing is banked, and the deposit stays visible for the reconciliation sweep.
     */
    public function test_a_checkout_return_that_flutterwave_does_not_confirm_credits_nothing(): void
    {
        $user = $this->memberWithBalance(0);
        $this->configureFlutterwave();
        Notification::fake();

        Http::fake([
            'flutter.test/*' => Http::response([
                'status' => 'success',
                'message' => 'Container initialized for payment',
                'data' => ['link' => 'https://flutter.test/checkout/abc'],
            ]),
        ]);

        $this->actingAs($user)
            ->post('/wallet/fund', ['amount' => 2000, 'consent_agreed' => '1'])
            ->assertRedirect();

        $tx = WalletTransaction::query()
            ->where('wallet_id', $user->wallet->id)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($user)->get('/wallet/flutterwave/callback?'
            .http_build_query(['tx_ref' => $tx->reference, 'transaction_id' => '', 'status' => 'cancelled']))
            ->assertRedirect(route('wallet.fund'))
            ->assertSessionHas('error', 'Payment was not completed.');

        $this->assertSame(0, (int) $user->fresh()->wallet->balance);
        $this->assertSame('failed', WalletTransaction::query()->where('id', $tx->id)->value('status'));
        $this->assertSame(0, DB::table('flutterwave_charge_claims')->count());
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
