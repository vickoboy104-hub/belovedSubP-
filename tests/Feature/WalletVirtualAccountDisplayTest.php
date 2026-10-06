<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's complaint was that a permanent account number appeared on the
 * Fund Wallet page but not on the dashboard, which is exactly the wrong way
 * round for the page a customer looks at every day.
 */
class WalletVirtualAccountDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->email_verified_at = now();
        $user->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 250_000]);

        return $user->fresh();
    }

    public function test_the_dashboard_shows_the_same_permanent_number_as_the_fund_page(): void
    {
        $user = $this->member([
            'virtual_account_number' => '01234567890',
            'virtual_account_bank' => 'Wema Bank',
            'virtual_account_name' => 'TEST CUSTOMER',
        ]);

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $fund = $this->actingAs($user)->get('/wallet/fund')->assertOk()->getContent();

        $this->assertStringContainsString('01234567890', $dashboard);
        $this->assertStringContainsString('data-copy-text="01234567890"', $dashboard);
        $this->assertStringContainsString('data-copy-text="01234567890"', $fund);
    }

    public function test_a_member_with_only_a_one_time_account_still_sees_it_on_the_dashboard(): void
    {
        $user = $this->member([
            'virtual_account_metadata' => [
                'temporary_virtual_account' => [
                    'account_number' => '09876543210',
                    'bank_name' => 'Moniepoint',
                    'expires_at' => now()->addHours(6)->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('09876543210');
    }

    public function test_an_expired_one_time_account_is_not_shown_as_spendable(): void
    {
        $user = $this->member([
            'virtual_account_metadata' => [
                'temporary_virtual_account' => [
                    'account_number' => '09876543210',
                    'bank_name' => 'Moniepoint',
                    'expires_at' => now()->subHour()->toIso8601String(),
                ],
            ],
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('09876543210');
    }

    public function test_a_member_with_no_account_is_offered_a_way_to_get_one(): void
    {
        $this->actingAs($this->member())
            ->get('/wallet/fund')
            ->assertOk()
            // Either the assign form or the copy of the number must be present,
            // otherwise the page shows a payment instruction with nowhere to pay.
            ->assertSee('Flutterwave', false);
    }

    public function test_the_fund_page_offers_round_amounts_and_the_deposit_fee(): void
    {
        $html = $this->actingAs($this->member())->get('/wallet/fund')->assertOk()->getContent();

        foreach (['500', '1000', '2000', '5000', '10000'] as $amount) {
            $this->assertStringContainsString('data-amount="'.$amount.'"', $html);
        }

        $this->assertStringContainsString('id="fundNetText"', $html);
        $this->assertStringContainsString('fund-quick-amount', $html);
    }

    public function test_the_webhook_url_is_shown_where_the_owner_copies_it(): void
    {
        $admin = $this->member(['is_admin' => true]);

        $html = $this->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('/wallet/flutterwave/webhook-v2', $html);
    }
}
