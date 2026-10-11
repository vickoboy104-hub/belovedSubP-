<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
