<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PurchaseIntent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A wallet is spent by a request, and the same request reaches this site more than
 * once: the double-tap on Buy, the browser retrying a POST it believes timed out,
 * the customer pressing Back and Submit again. Nothing in the figures they typed
 * tells those apart from somebody genuinely buying a second N500 of airtime, so the
 * only thing that can is an identity the form was given before it was sent - and the
 * only thing strong enough to hold it is the database.
 *
 * These tests hold two lines at once. One intention buys one purchase, even when the
 * request arrives twice and even when the first answer has not been written down yet;
 * and a customer who wants the same thing again, with a fresh intention, is served
 * exactly as they were the first time. A provider call is money too, so a repeat that
 * would have asked again is refused before the asking.
 */
class PurchaseIntentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const NIN = '12345678901';
    private const BVN = '44075744122';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->where('user_id', $user->id)->value('balance');
    }

    private function movements(User $user): int
    {
        return WalletTransaction::query()
            ->where('wallet_id', Wallet::query()->where('user_id', $user->id)->value('id'))
            ->count();
    }

    private function ordersOf(User $user): int
    {
        return Order::query()->where('user_id', $user->id)->count();
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function giveTheProviderAKey(): void
    {
        Setting::query()->create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        Setting::query()->create(['key' => 'nin_api_key', 'value' => 'test-nin-key']);
        Setting::query()->create(['key' => 'bvn_api_key', 'value' => 'test-bvn-key']);
        settings_flush_cache();
    }

    private function fakeCardProvider(): void
    {
        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'CARD-1',
                'content' => ['pin' => '9AB34CD71EF', 'serial_number' => '123456789012345'],
            ]),
        ]);
    }

    /** @return array<string, string> */
    private function cardPayload(string $intent): array
    {
        return [
            'pin_code' => 'neco',
            'phone' => '08012345678',
            PurchaseIntent::FIELD => $intent,
        ];
    }

    public function test_one_intention_books_the_purchase_once_however_many_times_it_arrives(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $first = $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0001'));
        $second = $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0001'));

        $first->assertOk()->assertJson(['ok' => true]);
        $second->assertStatus(409)->assertJson(['ok' => false, 'duplicate' => true]);

        $this->assertSame(1, $this->ordersOf($user));
        $this->assertSame(1, $this->movements($user));
        $this->assertSame(
            1_000_000 - (int) $this->latestOrder($user)->amount,
            $this->balance($user)
        );
    }

    public function test_a_repeat_is_refused_before_the_provider_is_asked_again(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0002'))->assertOk();
        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0002'))->assertStatus(409);

        // A provider call is money leaving the owner's account, so the second copy of
        // the intention must be turned away before the asking, not only before the
        // customer's own wallet is touched.
        Http::assertSentCount(1);
    }

    public function test_a_fresh_intention_buys_the_same_thing_again(): void
    {
        $user = $this->memberWithBalance(5_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0003'))
            ->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0004'))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, $this->ordersOf($user));
        $this->assertSame(2, $this->movements($user));
    }

    public function test_the_purchase_an_intention_bought_can_be_traced_back_to_it(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $this->actingAs($user)
            ->postJson('/vtu/exam/buy', $this->cardPayload('intention-0005'))
            ->assertOk();

        $this->assertSame('intention-0005', $this->latestOrder($user)->intent_token);
    }

    public function test_a_request_that_could_not_be_paid_for_gives_its_intention_back(): void
    {
        $user = $this->memberWithBalance(1_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        // Too thin to buy the card, so nothing was booked and the intention has to be
        // free again - otherwise the customer could never buy it without reloading.
        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0006'))
            ->assertStatus(402);

        $this->assertSame(0, $this->ordersOf($user));
        $this->assertSame(0, $this->movements($user));
        $this->assertSame(0, DB::table('purchase_intents')->where('token', 'intention-0006')->count());

        Wallet::query()->where('user_id', $user->id)->update(['balance' => 1_000_000]);

        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0006'))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, $this->ordersOf($user));
    }

    public function test_a_request_carrying_no_intention_is_still_served(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        // A form that somehow lost its identity is a gap to be closed, not a reason to
        // turn a paying customer away.
        $this->actingAs($user)->postJson('/vtu/exam/buy', ['pin_code' => 'neco', 'phone' => '08012345678'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, $this->ordersOf($user));
        $this->assertNull($this->latestOrder($user)->intent_token);
    }

    public function test_a_repeat_identity_is_refused_before_the_provider_is_asked_a_second_time(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();

        Http::fake([
            'confirmident.com.ng/api/nin_search' => Http::response([
                'success' => true,
                'message' => 'Record found',
                'data' => ['nin' => self::NIN, 'firstname' => 'Test', 'lastname' => 'Candidate'],
            ]),
        ]);

        $payload = ['search_type' => 'by_nin', 'nin' => self::NIN, PurchaseIntent::FIELD => 'intention-0007'];

        $this->actingAs($user)->postJson('/vtu/nin/search', $payload)
            ->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($user)->postJson('/vtu/nin/search', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'duplicate' => true]);

        // ₦160 a lookup, so a second asking is a real loss even when the wallet was
        // only debited once.
        Http::assertSentCount(1);
        $this->assertSame(1, $this->ordersOf($user));
    }

    public function test_a_bvn_check_is_not_bought_twice_by_a_repeat_of_one_intention(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();

        Http::fake([
            'confirmident.com.ng/api/bvn_search' => Http::response([
                'success' => true,
                'message' => 'Record found',
                'data' => ['bvn' => self::BVN, 'fullname' => 'Test Candidate'],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => self::BVN, PurchaseIntent::FIELD => 'intention-0008'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($user)
            ->postJson('/vtu/bvn/verify', ['bvn' => self::BVN, PurchaseIntent::FIELD => 'intention-0008'])
            ->assertStatus(409);

        Http::assertSentCount(1);
        $this->assertSame(1, $this->ordersOf($user));
        $this->assertSame(1, $this->movements($user));
    }

    public function test_a_hand_worked_request_is_taken_once_and_its_second_copy_is_answered(): void
    {
        $user = $this->memberWithBalance(5_000_000);

        $payload = ['ipe_type' => 'new_enrollment', 'tracking_id' => 'ABCDEFGHIJKLMNO'];

        $first = $this->actingAs($user)->post('/vtu/manual/ipe_clearance', $payload + [
            PurchaseIntent::FIELD => 'intention-0009',
        ]);
        $first->assertRedirect();

        $second = $this->actingAs($user)->post('/vtu/manual/ipe_clearance', $payload + [
            PurchaseIntent::FIELD => 'intention-0009',
        ]);
        $second->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, $this->ordersOf($user));
        $this->assertSame(1, $this->movements($user));
        $this->assertStringContainsString(
            'already',
            (string) session('error'),
            'The customer is not told which request their copy repeats.'
        );
    }

    public function test_the_second_copy_of_a_hand_worked_request_is_answered_before_its_paper_is_held(): void
    {
        Storage::fake('local');
        $user = $this->memberWithBalance(9_000_000);

        // The document a paid request needs is filed before the money moves, so a copy
        // that is already refused must not leave a second copy of that paper on the
        // disk for an agent to wonder about.
        $this->actingAs($user)->post('/vtu/manual/nin_modification', [
            'nin' => self::NIN,
            'email_choice' => 'auto',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849A',
            'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg'),
            'consent_agreed' => '1',
            PurchaseIntent::FIELD => 'intention-0010',
        ])->assertRedirect();

        $this->assertSame(1, $this->ordersOf($user));
        $this->assertSame('intention-0010', $this->latestOrder($user)->intent_token);

        $this->actingAs($user)->post('/vtu/manual/nin_modification', [
            'nin' => self::NIN,
            'email_choice' => 'auto',
            'field_to_modify' => 'dob',
            'attestation_no' => 'SO1250325358849A',
            'attestation_img' => UploadedFile::fake()->create('attestation.jpg', 40, 'image/jpeg'),
            'consent_agreed' => '1',
            PurchaseIntent::FIELD => 'intention-0010',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, $this->ordersOf($user));
        $this->assertSame(1, $this->movements($user));
    }

    public function test_the_database_holds_one_intention_for_one_customer_once(): void
    {
        $customer = User::factory()->create();

        PurchaseIntent::claim((int) $customer->id, 'shared-identity-1');

        // The lock is the table's own unique index, not a check-then-write in PHP: two
        // requests that both looked first would both have written.
        $this->expectException(QueryException::class);
        DB::table('purchase_intents')->insert([
            'user_id' => $customer->id,
            'token' => 'shared-identity-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_second_customer_can_hold_the_same_words_but_not_the_first_customers_request(): void
    {
        $customer = User::factory()->create();
        $neighbour = User::factory()->create();

        $this->assertTrue(PurchaseIntent::claim((int) $customer->id, 'same-words'));
        $this->assertTrue(PurchaseIntent::claim((int) $neighbour->id, 'same-words'));
        $this->assertFalse(PurchaseIntent::claim((int) $customer->id, 'same-words'));
    }

    public function test_the_customer_is_told_which_purchase_their_copy_repeats(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0011'))->assertOk();

        $message = (string) $this->actingAs($user)
            ->postJson('/vtu/exam/buy', $this->cardPayload('intention-0011'))
            ->json('message');

        // Words that answer the question the customer is actually asking: did my money
        // go out twice?
        $this->assertStringContainsString('already completed', $message);
        $this->assertStringContainsString('charged once', $message);
    }

    public function test_every_purchase_page_ships_an_intention_with_its_form(): void
    {
        $user = $this->memberWithBalance(1_000_000);

        // The pages that own a form this site takes money through. The operator,
        // cable and meter chooser pages are lists of links and carry no form, so a
        // purchase page is only reached once a customer has named what they are buying.
        $pages = [
            '/vtu/airtime/mtn' => 1,
            '/vtu/data/mtn_sme' => 1,
            '/vtu/recharge-card' => 1,
            '/vtu/cable/dstv' => 1,
            '/vtu/electricity/ikeja-electric' => 1,
            '/vtu/exam/jamb' => 1,
            '/vtu/premium-apps' => 1,
            '/vtu/nin' => 1,
            '/vtu/nin-validation' => 1,
            '/vtu/bvn' => 3,
            '/vtu/manual/ipe_clearance' => 1,
        ];

        foreach ($pages as $path => $expected) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            // Counting the field the browser will actually send, never the word
            // "intent_token" as it appears in the page's own script.
            $fields = $this->intentionFields($html);

            $this->assertGreaterThanOrEqual(
                $expected,
                count($fields),
                $path.' shows a purchase form without an intention to go with it.'
            );

            foreach ($fields as $field) {
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z0-9_-]{8,64}$/',
                    $field,
                    $path.' ships an intention the server would throw away as unusable.'
                );
            }
        }
    }

    public function test_each_intention_on_a_page_is_its_own_and_not_a_copy_of_another(): void
    {
        $user = $this->memberWithBalance(1_000_000);

        $html = $this->actingAs($user)->get('/vtu/bvn')->assertOk()->getContent();

        // Three forms on one page, and one bought check must not refuse a slip printed
        // in the same minute.
        $tokens = $this->intentionFields($html);

        $this->assertCount(3, $tokens);
        $this->assertCount(3, array_unique($tokens));
    }

    /**
     * The intentions a page hands to the browser, in the order they appear.
     *
     * @return array<int, string>
     */
    private function intentionFields(string $html): array
    {
        preg_match_all(
            '/<input\b(?=[^>]*\bname=(["\'])intent_token\1)(?=[^>]*\bvalue=(["\'])[^"]*\2)[^>]*>/i',
            $html,
            $matches
        );

        $found = [];
        foreach ($matches[0] as $tag) {
            if (preg_match('/\bvalue=(["\'])(.*?)\1/is', $tag, $value) === 1) {
                $found[] = $value[2];
            }
        }

        return $found;
    }

    public function test_a_request_answered_as_a_repeat_leaves_nothing_behind(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->giveTheProviderAKey();
        $this->fakeCardProvider();

        $before = $this->balance($user);
        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0012'))->assertOk();

        $charged = $before - $this->balance($user);
        $orders = $this->ordersOf($user);
        $rows = $this->movements($user);

        $this->actingAs($user)->postJson('/vtu/exam/buy', $this->cardPayload('intention-0012'))->assertStatus(409);

        // A refusal is not a part-purchase: no second debit, no second order, no
        // dangling claim that has no order behind it.
        $this->assertSame($charged, $before - $this->balance($user));
        $this->assertSame($orders, $this->ordersOf($user));
        $this->assertSame($rows, $this->movements($user));
    }
}
