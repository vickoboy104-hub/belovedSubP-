<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The identity business sells the same job twice: once to the customer and once
 * to the provider. Only the difference between those two numbers is business,
 * and a verification read back out of a record already held never asks the
 * provider at all.
 */
class IdentityProfitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_nin_verification_books_the_provider_price_as_its_cost(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->giveTheProviderAKey();
        $this->fakeNinSearch();

        $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '12345678901',
        ])->assertOk()->assertJson(['ok' => true]);

        $order = $this->latestOrder($user);

        // The shipped retail for a verification, less what ConfirmIdent charges
        // for the same lookup.
        $this->assertSame(25_000, (int) $order->amount);
        $this->assertSame(9_000, (int) $order->profit);
        $this->assertSame(160.0, (float) $order->meta['provider_cost_naira']);
    }

    public function test_a_repeat_read_from_the_stored_record_keeps_the_whole_charge(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->giveTheProviderAKey();
        $this->fakeNinSearch();

        foreach (['12345678901', '12345678901'] as $nin) {
            $this->actingAs($user)->postJson('/vtu/nin/search', [
                'search_type' => 'by_nin',
                'nin' => $nin,
            ])->assertOk()->assertJson(['ok' => true]);
        }

        // The second answer came out of the record held here, so the provider was
        // paid once while the customer paid twice.
        Http::assertSentCount(1);

        [$first, $repeat] = Order::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $this->assertFalse((bool) $first->meta['cache_hit']);
        $this->assertTrue((bool) $repeat->meta['cache_hit']);
        $this->assertSame(0.0, (float) $repeat->meta['provider_cost_naira']);
        $this->assertSame(9_000, (int) $first->profit);
        $this->assertSame(25_000, (int) $repeat->profit);
    }

    public function test_a_verification_worked_by_a_person_books_the_same_margin(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->giveTheProviderAKey();

        Setting::create(['key' => 'nin_verify_mode', 'value' => 'manual']);
        settings_flush_cache();

        Http::fake();

        $this->actingAs($user)->postJson('/vtu/nin/search', [
            'search_type' => 'by_nin',
            'nin' => '12345678901',
        ])->assertOk()->assertJson(['queued' => true]);

        $order = $this->latestOrder($user);

        // The queue costs the same rate at the provider as the endpoint would,
        // so the margin is the same whichever way the answer arrives.
        $this->assertSame(25_000, (int) $order->amount);
        $this->assertSame(9_000, (int) $order->profit);
        $this->assertSame(160.0, (float) $order->meta['provider_cost_naira']);
    }

    public function test_a_slip_drawn_here_keeps_every_kobo_of_the_slip_price(): void
    {
        $user = $this->memberWithBalance(100_000);
        $verified = $this->verifiedRecord($user);

        $this->actingAs($user)->postJson('/vtu/nin/print', [
            'slip_type' => 'standard_slip',
            'verification_order_id' => $verified->id,
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertNothingSent();

        $print = $this->latestOrder($user);

        $this->assertSame(35_000, (int) $print->amount);
        $this->assertSame(35_000, (int) $print->profit);
        $this->assertSame(0.0, (float) $print->meta['provider_cost_naira']);
    }

    public function test_a_slip_the_provider_prints_is_counted_after_its_own_price(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->giveTheProviderAKey();
        Setting::create(['key' => 'nin_print_endpoint', 'value' => '/nin_print']);
        settings_flush_cache();

        Http::fake([
            'confirmident.com.ng/api/nin_print' => Http::response([
                'success' => true,
                'message' => 'Slip generated',
                'data' => ['nin' => '12345678901', 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
        ]);

        $this->actingAs($user)->postJson('/vtu/nin/print', [
            'slip_type' => 'long_slip',
            'verification_type' => 'by_nin',
            'nin' => '12345678901',
        ])->assertOk()->assertJson(['ok' => true]);

        $print = $this->latestOrder($user);

        // The long slip retails at 300 and costs 180 at the provider.
        $this->assertSame(30_000, (int) $print->amount);
        $this->assertSame(12_000, (int) $print->profit);
        $this->assertSame(180.0, (float) $print->meta['provider_cost_naira']);
    }

    public function test_the_dashboard_names_what_the_nin_business_kept(): void
    {
        $admin = $this->admin();
        $user = $this->memberWithBalance(200_000);
        $verified = $this->verifiedRecord($user, charged: 25_000, kept: 9_000);

        $this->printRecord($user, $verified);

        $board = $this->ninSection($this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent());

        // One verification and one slip drawn here from it: 550 charged, and the
        // 390 of it that was not paid onward to the provider.
        $this->assertSame(
            ['All time', '1', '0', '1', '&#8358;550.00', '&#8358;390.00'],
            $this->ninCells($board, 'All time')
        );
    }

    public function test_the_board_counts_a_repeat_read_from_the_stored_record(): void
    {
        $admin = $this->admin();
        $user = $this->memberWithBalance(100_000);

        $this->verifiedRecord($user, charged: 25_000, kept: 9_000);
        $this->verifiedRecord($user, charged: 25_000, kept: 25_000, repeat: true);

        $board = $this->ninSection($this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent());

        // Two verifications, one of which cost nothing to answer.
        $this->assertSame(
            ['All time', '2', '1', '0', '&#8358;500.00', '&#8358;340.00'],
            $this->ninCells($board, 'All time')
        );
    }

    public function test_a_member_cannot_see_the_nin_board(): void
    {
        $this->actingAs($this->memberWithBalance(100_000))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_the_recount_reports_an_old_order_without_writing_it(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->verifiedRecord($user, charged: 25_000, kept: 0);

        $this->artisan('identity:recount-profit')
            ->expectsOutputToContain('90.00')
            ->assertSuccessful();

        $this->assertSame(0, (int) $this->latestOrder($user)->profit);
    }

    public function test_the_recount_gives_an_old_order_the_margin_it_actually_kept(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->verifiedRecord($user, charged: 25_000, kept: 0);

        $this->artisan('identity:recount-profit', ['--apply' => true])->assertSuccessful();

        $this->assertSame(9_000, (int) $this->latestOrder($user)->profit);
    }

    public function test_the_recount_leaves_alone_a_job_with_no_rate_on_file(): void
    {
        $user = $this->memberWithBalance(100_000);

        Order::create([
            'user_id' => $user->id,
            'service_id' => $this->serviceFor('nin_agreement')->id,
            'customer_ref' => '74227342856',
            'amount' => 300_000,
            'profit' => 50_000,
            'status' => 'success',
            'provider' => 'manual',
            'meta' => [
                'type' => 'manual_service',
                'manual_queue' => true,
                'manual_service' => 'nin_agreement',
                'submitted' => ['nin' => '74227342856', 'state' => 'Lagos'],
            ],
        ]);

        $this->artisan('identity:recount-profit', ['--apply' => true])->assertSuccessful();

        // Nothing is known about what the provider asks for this one, so the
        // markup the owner booked stays rather than a guessed margin.
        $this->assertSame(50_000, (int) $this->latestOrder($user)->profit);
    }

    public function test_the_recount_knows_a_stored_repeat_cost_nothing(): void
    {
        $user = $this->memberWithBalance(100_000);
        $this->verifiedRecord($user, charged: 25_000, kept: 0, repeat: true);

        $this->artisan('identity:recount-profit', ['--apply' => true])->assertSuccessful();

        $this->assertSame(25_000, (int) $this->latestOrder($user)->profit);
    }

    private function fakeNinSearch(): void
    {
        Http::fake([
            'confirmident.com.ng/api/nin_search' => Http::response([
                'success' => true,
                'message' => 'Record found',
                'data' => ['nin' => '12345678901', 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
        ]);
    }

    private function giveTheProviderAKey(): void
    {
        Setting::create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        settings_flush_cache();
    }

    /**
     * A verification already paid for, in the shape the record page leaves it.
     */
    private function verifiedRecord(User $user, int $charged = 25_000, int $kept = 9_000, bool $repeat = false): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'service_id' => $this->serviceFor('nin_verify')->id,
            'customer_ref' => '44075744122',
            'amount' => $charged,
            'profit' => $kept,
            'status' => 'success',
            'provider' => 'nin_api',
            'meta' => [
                'type' => 'nin',
                'service_type' => 'verify',
                'verification_type' => $repeat ? 'by_phone' : 'by_nin',
                'cache_hit' => $repeat,
                'normalized' => ['nin' => '44075744122', 'full_name' => 'ESTHER CHIOMA OKECHUKWU'],
                'provider_response' => ['success' => true, 'data' => ['nin' => '44075744122']],
            ],
        ]);
    }

    private function printRecord(User $user, Order $verified): void
    {
        $this->actingAs($user)->postJson('/vtu/nin/print', [
            'slip_type' => 'long_slip',
            'verification_order_id' => $verified->id,
        ])->assertOk();
    }

    /** The dashboard's NIN block on its own, so an assertion cannot be satisfied by a tile elsewhere. */
    private function ninSection(string $page): string
    {
        $start = strpos($page, 'NIN Verification &amp; Printing');
        $this->assertNotFalse($start, 'The dashboard has no NIN block at all.');

        $end = strpos($page, 'Reset Totals', $start);
        $this->assertNotFalse($end, 'The NIN block never ends.');

        return substr($page, $start, $end - $start);
    }

    /**
     * One window's row, cell by cell, so a figure cannot be picked up from the
     * row above it or from another table on the page.
     *
     * @return list<string>
     */
    private function ninCells(string $board, string $window): array
    {
        $labelled = strpos($board, '>'.$window.'<');
        $this->assertNotFalse($labelled, "The NIN board has no {$window} row.");

        $row = strrpos(substr($board, 0, $labelled), '<tr');
        $this->assertNotFalse($row);

        $end = strpos($board, '</tr>', $labelled);
        $this->assertNotFalse($end);

        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', substr($board, $row, $end - $row), $cells);

        return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
    }

    private function serviceFor(string $slug): Service
    {
        return Service::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => str_replace('_', ' ', ucwords($slug))]
        );
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }
}
