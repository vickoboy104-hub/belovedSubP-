<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletStatsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_the_wallet_board_lists_members_with_their_balances(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['name' => 'Aisha Bello', 'email' => 'aisha@example.test']);
        $wallet = Wallet::query()->updateOrCreate(['user_id' => $member->id], ['balance' => 250000]);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'channel' => 'flutterwave',
            'amount' => 250000,
            'status' => 'success',
            'reference' => 'test-credit-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($admin)->get('/admin/wallet-stats')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Aisha Bello', $html);
        $this->assertStringContainsString('2,500.00', $html);
        $this->assertStringContainsString('Card and bank checkout', $html, 'The funding source is not named on the board.');
        $this->assertStringContainsString('<svg class="app-chart', $html);
        $this->assertStringContainsString('class="app-bar-fill"', $html);
    }

    public function test_the_board_searches_members_by_name(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Chidi Okonkwo', 'email' => 'chidi@example.test']);
        User::factory()->create(['name' => 'Fatima Yusuf', 'email' => 'fatima@example.test']);

        $html = $this->actingAs($admin)->get('/admin/wallet-stats?search=Fatima')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fatima Yusuf', $html);
        $this->assertStringNotContainsString('Chidi Okonkwo', $html);
    }

    public function test_a_member_cannot_open_the_board(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/wallet-stats')
            ->assertForbidden();
    }
}
