<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\IssuedKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A customer who pays for a scratch card has to end up holding the card. Until
 * now the provider's answer was stored whole and the receipt looked for a
 * meta.pin that no code ever wrote, so paid-for keys sat unread in the database.
 */
class ExamKeyRevealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_a_card_the_provider_answered_with_reaches_the_customer(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->configureProvider();

        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'EXAM-1',
                'content' => [
                    'pin' => '9AB34CD71EF',
                    'serial_number' => '123456789012345',
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->postJson('/vtu/exam/buy', [
            'pin_code' => 'neco',
            'phone' => '08012345678',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        // The popup is where most people stop reading, so the key has to be there.
        $message = $response->json('message');
        $this->assertStringContainsString('9AB34CD71EF', $message);
        $this->assertStringContainsString('123456789012345', $message);

        $order = $this->latestOrder($user);
        $this->assertSame('success', $order->status);
        $this->assertSame([
            ['label' => 'Serial Number', 'value' => '123456789012345'],
            ['label' => 'PIN', 'value' => '9AB34CD71EF'],
        ], $order->meta['keys']);

        $receipt = $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->getContent();
        $this->assertStringContainsString('9AB34CD71EF', $receipt);
        $this->assertStringContainsString('Your keys', $receipt);
    }

    public function test_the_customer_can_copy_and_download_every_key_on_the_receipt(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $order = $this->orderWithKeys($user, [
            ['label' => 'Serial Number', 'value' => '123456789012345'],
            ['label' => 'PIN', 'value' => '9AB34CD71EF'],
        ]);

        $receipt = $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->getContent();

        // One copy control per key, wired to the site-wide clipboard handler.
        $this->assertSame(2, substr_count($receipt, 'data-copy-text='));
        $this->assertStringContainsString('data-copy-text="9AB34CD71EF"', $receipt);

        // Downloading reads the values off the page instead of baking them into
        // the script, so a key can never break the receipt.
        $this->assertStringContainsString('function downloadKeys()', $receipt);
        $this->assertStringContainsString('onclick="downloadKeys()"', $receipt);
        $this->assertStringContainsString('data-key-value="9AB34CD71EF"', $receipt);

        // Printing is how a card gets handed to a school, so the values stay on
        // the sheet and only the buttons leave.
        $this->assertStringContainsString('onclick="window.print()"', $receipt);
        $this->assertStringContainsString('class="no-print', $receipt);
    }

    public function test_a_key_never_reaches_eyes_that_did_not_buy_the_card(): void
    {
        $buyer = $this->memberWithBalance(1_000_000);
        $neighbour = $this->memberWithBalance(1_000_000);

        $order = $this->orderWithKeys($buyer, [['label' => 'PIN', 'value' => 'ONLYTHEBUYERHASIT']]);

        $this->actingAs($buyer)->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('ONLYTHEBUYERHASIT', false);

        $this->actingAs($neighbour)->get('/vtu/receipt/'.$order->id)->assertNotFound();

        // The receipt is the only place the key is spoken, so a stranger browsing
        // the transaction list learns nothing.
        $this->actingAs($neighbour)->get('/vtu/orders')->assertOk()->assertDontSee('ONLYTHEBUYERHASIT', false);
    }

    public function test_the_owner_can_issue_by_hand_a_card_the_provider_never_sent(): void
    {
        $admin = $this->admin();
        $user = $this->memberWithBalance(1_000_000);

        // Paid, marked successful, and nothing came back: the customer is holding
        // a receipt for a card they cannot use.
        $order = Order::create([
            'user_id' => $user->id,
            'service_id' => $this->serviceId('neco'),
            'customer_ref' => '08012345678',
            'amount' => 510_000,
            'provider' => 'gsubz',
            'provider_reference' => 'EXAM-2',
            'status' => 'success',
            'meta' => [
                'type' => 'exam',
                'pin_code' => 'neco',
                'provider_response' => ['status' => '1', 'description' => 'Transaction successful'],
            ],
        ]);

        $this->assertSame([], IssuedKeys::forOrder($order->fresh()));

        // The page itself has to render before anything can be typed into it.
        $page = $this->actingAs($admin)->get('/admin/orders/'.$order->id.'/keys')->assertOk()->getContent();
        $this->assertStringContainsString('Issue the keys', $page);
        $this->assertStringContainsString('no key ever arrived', $page);
        $this->assertStringContainsString('x-for="(row, index) in rows"', $page);

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/keys', [
            'keys' => [
                ['label' => 'Serial Number', 'value' => '  987654321098765 '],
                ['label' => 'PIN', 'value' => 'ZZ112233445'],
                ['label' => '', 'value' => ''],
            ],
        ])->assertRedirect();

        $issued = $order->fresh();
        $this->assertSame([
            ['label' => 'Serial Number', 'value' => '987654321098765'],
            ['label' => 'PIN', 'value' => 'ZZ112233445'],
        ], $issued->meta['keys']);

        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('ZZ112233445', false);

        // The notice points at the receipt. A notification is stored, mailed and
        // read over shoulders, so the card itself never travels in it.
        $notice = $user->notifications()->firstOrFail();
        $this->assertSame('order_keys_issued', $notice->data['type']);
        $this->assertStringNotContainsString('ZZ112233445', json_encode($notice->data));
        $this->assertStringContainsString('/vtu/receipt/'.$order->id, $notice->data['url']);
    }

    public function test_only_the_owner_of_a_card_can_issue_its_keys(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $order = $this->orderWithKeys($user, [['label' => 'PIN', 'value' => 'NOTFORYOUTOSEE']]);

        $this->actingAs($user)->get('/admin/orders/'.$order->id.'/keys')->assertForbidden();
        $this->actingAs($user)->post('/admin/orders/'.$order->id.'/keys', [
            'keys' => [['label' => 'PIN', 'value' => 'HACKED']],
        ])->assertForbidden();

        $this->assertSame('NOTFORYOUTOSEE', $order->fresh()->meta['keys'][0]['value']);
    }

    public function test_a_meter_token_is_revealed_the_same_way_as_an_exam_pin(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->configureProvider();

        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'ELE-1',
                'token' => '4432-9987-1123-4477-0091',
            ]),
        ]);

        $response = $this->actingAs($user)->postJson('/vtu/electricity/buy', [
            'service_id' => 'ikeja-electric',
            'meter_type' => 'prepaid',
            'customer_ref' => 'IE40028915023',
            'amount' => 5_000,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('4432-9987-1123-4477-0091', $response->json('message'));

        $order = $this->latestOrder($user);
        $this->assertSame('Token', $order->meta['keys'][0]['label']);

        $this->actingAs($user)->get('/vtu/receipt/'.$order->id)
            ->assertOk()
            ->assertSee('4432-9987-1123-4477-0091', false);
    }

    public function test_a_word_the_provider_echoed_back_is_not_handed_over_as_a_key(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->configureProvider();

        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'status' => '1',
                'description' => 'Transaction successful',
                'transactionID' => 'EXAM-3',
                // The request's own product selector and phone number, echoed.
                'pin_code' => 'neco',
                'phone' => '08012345678',
                'requestID' => 'EXAM-3',
            ]),
        ]);

        $this->actingAs($user)->postJson('/vtu/exam/buy', [
            'pin_code' => 'neco',
            'phone' => '08012345678',
        ])->assertOk()->assertJson(['ok' => true]);

        $order = $this->latestOrder($user);

        // Showing "neco" as a key would teach the customer that they were sold
        // nothing, so the card is left absent and the admin has to issue it.
        $this->assertSame([], IssuedKeys::forOrder($order));
        $this->assertStringNotContainsString('Your keys',
            $this->actingAs($user)->get('/vtu/receipt/'.$order->id)->assertOk()->getContent());
    }

    public function test_a_key_line_that_is_too_long_or_too_many_is_refused(): void
    {
        $admin = $this->admin();
        $user = $this->memberWithBalance(1_000_000);
        $order = $this->orderWithKeys($user, [['label' => 'PIN', 'value' => 'ORIGINALVALUE']]);

        $rows = [];
        for ($i = 0; $i <= IssuedKeys::MAX_KEYS; $i++) {
            $rows[] = ['label' => 'PIN', 'value' => 'VALUE'.$i];
        }

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/keys', ['keys' => $rows])
            ->assertSessionHasErrors('keys');

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/keys', [
            'keys' => [['label' => 'PIN', 'value' => str_repeat('A', 200)]],
        ])->assertSessionHasErrors('keys.0.value');

        $this->assertSame('ORIGINALVALUE', $order->fresh()->meta['keys'][0]['value']);
    }

    public function test_the_receipt_offers_the_key_where_the_customer_will_look_for_it(): void
    {
        $user = $this->memberWithBalance(1_000_000);
        $this->orderWithKeys($user, [['label' => 'PIN', 'value' => 'LOOKFORMEHERE']]);

        $list = $this->actingAs($user)->get('/vtu/orders')->assertOk()->getContent();
        $this->assertStringContainsString('View keys', $list);
    }

    // ---------------------------------------------------------

    private function orderWithKeys(User $user, array $keys): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'service_id' => $this->serviceId('waec'),
            'customer_ref' => '08012345678',
            'amount' => 560_000,
            'provider' => 'gsubz',
            'provider_reference' => 'EXAM-'.uniqid(),
            'status' => 'success',
            'meta' => [
                'type' => 'exam',
                'pin_code' => 'waec',
                'keys' => $keys,
            ],
        ]);
    }

    private function serviceId(string $slug): int
    {
        return \App\Models\Service::create(['slug' => $slug, 'name' => strtoupper($slug)])->id;
    }

    private function configureProvider(): void
    {
        Setting::create(['key' => 'provider_gsubz_api_key', 'value' => 'test-provider-key']);
        settings_flush_cache();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function memberWithBalance(int $kobo): User
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();
        Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => $kobo]);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => $kobo]);

        return $user->fresh();
    }

    private function latestOrder(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
