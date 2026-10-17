<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner's question is a plain one: who paid me today, or on the day I pick.
 * The old board could only answer "how much", and it cut its days on the UTC
 * calendar, so a customer who paid at half past midnight in Lagos was filed
 * under yesterday.
 */
class WhoPaidBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payment_in_the_first_hour_of_the_nigerian_day_is_still_today(): void
    {
        $admin = $this->admin();
        $customer = $this->member('Amara Nwosu');

        // 00:30 in Lagos is 23:30 of the previous day in UTC, where the rows live.
        $this->credit($customer, 250_000, 'flutterwave', $this->lagosMoment('00:30'));

        $html = $this->actingAs($admin)->get('/admin/wallet-stats')->assertOk()->getContent();
        $board = $this->paidBoard($html);

        $this->assertStringContainsString('Who paid today', $html);
        $this->assertStringContainsString('Amara Nwosu', $board);
        $this->assertStringContainsString('₦2,500.00', $board);
        $this->assertStringContainsString('Card and bank checkout', $board);
    }

    public function test_the_board_can_be_turned_back_to_yesterday(): void
    {
        $admin = $this->admin();
        $today = $this->member('Tunde Bakare');
        $yesterday = $this->member('Grace Etim');

        $this->credit($today, 100_000, 'flutterwave', $this->lagosMoment('12:00'));
        $this->credit($yesterday, 400_000, 'flutterwave_virtual_account', $this->lagosMoment('12:00', -1));

        $board = $this->paidBoard($this->actingAs($admin)->get('/admin/wallet-stats')->assertOk()->getContent());
        $this->assertStringContainsString('Tunde Bakare', $board);
        $this->assertStringNotContainsString('Grace Etim', $board);

        $back = $this->paidBoard($this->actingAs($admin)
            ->get('/admin/wallet-stats?paid_on='.Carbon::now('Africa/Lagos')->subDay()->toDateString())
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('Grace Etim', $back);
        $this->assertStringNotContainsString('Tunde Bakare', $back);
        $this->assertStringContainsString('Virtual account transfer', $back);
        $this->assertStringContainsString('Back to today', $back);
    }

    public function test_money_that_was_never_paid_in_is_not_counted_as_a_payment(): void
    {
        $admin = $this->admin();
        $customer = $this->member('Zainab Musa');

        $this->credit($customer, 90_000, 'refund', $this->lagosMoment('09:00'));
        $this->credit($customer, 80_000, 'referral_withdrawal', $this->lagosMoment('09:30'));
        $this->credit($customer, 70_000, 'flutterwave', $this->lagosMoment('10:00'));

        $board = $this->paidBoard($this->actingAs($admin)->get('/admin/wallet-stats')->assertOk()->getContent());

        // Only the ₦700 that actually arrived from outside counts as a payment.
        $this->assertStringContainsString('Zainab Musa', $board);
        $this->assertStringContainsString('₦700.00', $board);
        $this->assertStringContainsString('1 deposit', $board);
        $this->assertStringNotContainsString('₦900.00', $board);
        $this->assertStringNotContainsString('₦800.00', $board);
    }

    public function test_the_service_tab_shows_who_was_charged_and_for_what(): void
    {
        $admin = $this->admin();
        $buyer = $this->member('Emeka Okeke');

        $this->purchase($buyer, 'MTN 1GB Data', ['type' => 'data', 'plan' => 'MTN 1GB'], 1000, 'success', $this->lagosMoment('08:15'));
        $this->purchase($buyer, 'WAEC Result Checker', ['type' => 'exam'], 56_000, 'refunded', $this->lagosMoment('17:40'));

        $board = $this->paidBoard($this->actingAs($admin)
            ->get('/admin/wallet-stats?paid_kind=service')
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('Emeka Okeke', $board);
        $this->assertStringContainsString('MTN 1GB Data', $board);
        $this->assertStringContainsString('WAEC Result Checker', $board);
        $this->assertStringContainsString('Refunded', $board);
        $this->assertStringContainsString('2 purchases', $board);
        $this->assertStringContainsString('560.00 of it refunded', $board);
        $this->assertStringContainsString('MTN 1GB', $board, 'The plan they bought is not shown.');
        $this->assertStringContainsString('08012345678', $board, 'The number the service was delivered to is not shown.');
    }

    public function test_a_date_the_owner_could_not_have_meant_falls_back_to_today(): void
    {
        $admin = $this->admin();
        $this->credit($this->member('Blessing Ade'), 50_000, 'flutterwave', $this->lagosMoment('11:00'));

        $html = $this->actingAs($admin)
            ->get('/admin/wallet-stats?paid_on=not-a-date')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Who paid today', $html);
        $this->assertStringContainsString('Blessing Ade', $this->paidBoard($html));
    }

    public function test_a_member_cannot_see_who_paid(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/admin/wallet-stats?paid_kind=service')
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /**
     * Just the who-paid board. The rest of the page also counts refunds and
     * referral payouts, so asserting against the whole document would pass even
     * when the board itself is wrong.
     */
    private function paidBoard(string $html): string
    {
        $start = strpos($html, 'Who paid');
        $this->assertNotFalse($start, 'The who-paid board is missing from the statistics page.');

        $end = strpos($html, 'Deposits over the last', $start);
        $this->assertNotFalse($end, 'The who-paid board runs past the chart.');

        return substr($html, $start, $end - $start);
    }

    private function member(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);
    }

    /** A moment on the owner's own calendar, said in the calendar the rows use. */
    private function lagosMoment(string $clock, int $offsetDays = 0): string
    {
        return Carbon::now('Africa/Lagos')
            ->addDays($offsetDays)
            ->setTimeFromTimeString($clock)
            ->setTimezone('UTC')
            ->toDateTimeString();
    }

    private function credit(User $user, int $kobo, string $channel, string $at): void
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'channel' => $channel,
            'amount' => $kobo,
            'status' => 'success',
            'reference' => $channel.'-'.uniqid(),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function purchase(User $user, string $serviceName, array $meta, int $kobo, string $status, string $at): void
    {
        $order = Order::create([
            'user_id' => $user->id,
            'service_id' => Service::create([
                'slug' => 'svc-'.uniqid(),
                'name' => $serviceName,
            ])->id,
            'customer_ref' => '08012345678',
            'amount' => $kobo,
            'provider' => 'gsubz',
            'status' => $status,
            'meta' => $meta,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
    }
}
