<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sms\NullSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The owner's brief was bulk messaging to every customer phone and email, with
 * the phone half expected to be wired to a panel later. These tests are what
 * makes that promise safe: nothing is ever reported as texted until a gateway
 * both exists and says yes.
 */
class BulkSmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function admin(): User
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'first_name' => 'Vicko',
            'email' => 'owner@belovedsubp.test',
            'phone' => null,
        ]);

        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function member(string $phone): User
    {
        $member = User::factory()->create([
            'is_admin' => false,
            'first_name' => 'Member',
            'email' => 'member'.$this->sequence.'@belovedsubp.test',
            'phone' => $phone,
        ]);

        $this->sequence++;

        $member->email_verified_at = now();
        $member->save();

        return $member;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function saveSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // The whole table is cached as one blob, so a write is invisible until it is dropped.
        settings_flush_cache();
    }

    private function connectGateway(array $extra = []): void
    {
        $this->saveSettings(array_merge([
            'sms_endpoint' => 'https://sms.test.example/v1/send',
            'sms_api_key' => 'test-gateway-key',
            'sms_sender_id' => 'BELOVED',
        ], $extra));
    }

    public function test_the_sms_channel_is_offered_but_inert_until_a_gateway_is_connected(): void
    {
        Http::fake();

        $admin = $this->admin();
        $this->member('08031234567');

        $page = $this->actingAs($admin)->get('/admin/broadcast')->assertOk();

        // Disabled rather than hidden, so the owner can see the feature exists
        // and what unlocks it.
        $page->assertSee('Admin Settings → SMS Gateway', false);
        $this->assertStringContainsString('value="sms" disabled', $page->getContent());

        $result = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Network maintenance',
            'message' => 'DSTV recons may take longer today.',
            'channels' => ['database', 'sms'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertTrue($result['smsSkipped']);
        $this->assertSame(0, $result['sms_sent']);
        $this->assertSame(0, $result['sms_failed']);
        Http::assertNothingSent();
    }

    public function test_a_connected_gateway_receives_normalised_numbers_and_the_message_body(): void
    {
        $this->connectGateway();
        Http::fake(['sms.test.example/*' => Http::response(['code' => '0', 'message' => 'Queued'], 200)]);

        $admin = $this->admin();
        $this->member('08031234567');
        $this->member('+234 805 999 1122');

        // SMS alone, proving a text campaign no longer has to be paid for with
        // inbox entries the customer never asked for.
        $result = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Wallet top-up window',
            'message' => 'Bank transfers settle every 15 minutes today.',
            'channels' => ['sms'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertFalse($result['smsSkipped']);
        $this->assertSame(2, $result['sms_sent']);
        $this->assertSame(0, $result['sms_failed']);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://sms.test.example/v1/send'
                && $request->header('Authorization') === ['Bearer test-gateway-key']
                && $body['to'] === ['+2348031234567', '+2348059991122']
                && $body['from'] === 'BELOVED'
                && $body['message'] === 'Bank transfers settle every 15 minutes today.';
        });
    }

    public function test_a_success_status_hiding_a_failure_code_is_not_counted_as_delivery(): void
    {
        $this->connectGateway(['sms_success_field' => 'code', 'sms_success_value' => '0']);

        // The shape real panels use: 200 OK, and the bad news in the body.
        Http::fake(['sms.test.example/*' => Http::response(
            ['code' => '102', 'message' => 'Insufficient sender balance'],
            200,
        )]);

        $admin = $this->admin();
        $this->member('08031234567');

        $result = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Price update',
            'message' => 'Data bundles dropped this morning.',
            'channels' => ['sms'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertSame(0, $result['sms_sent']);
        $this->assertSame(1, $result['sms_failed']);
        $this->assertSame('Insufficient sender balance', $result['gateway_message']);
    }

    public function test_a_gateway_that_refuses_the_connection_is_reported_honestly(): void
    {
        $this->connectGateway();
        Http::fake(['sms.test.example/*' => Http::response(null, 500)]);

        $admin = $this->admin();
        $this->member('08031234567');

        $result = $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Service restoration',
            'message' => 'GOTV is back.',
            'channels' => ['sms'],
            'after' => 0,
        ])->assertOk()->json();

        $this->assertSame(0, $result['sms_sent']);
        $this->assertSame(1, $result['sms_failed']);
    }

    public function test_a_provider_that_names_its_fields_differently_needs_no_code_change(): void
    {
        $this->connectGateway([
            'sms_body_format' => 'form',
            'sms_auth_header' => 'x-api-key',
            'sms_param_map' => json_encode(['to' => 'destination', 'from' => 'senderID', 'message' => 'text']),
            'sms_extra_params' => json_encode(['type' => 'plain']),
        ]);

        Http::fake(['sms.test.example/*' => Http::response(['status' => true], 200)]);

        $admin = $this->admin();
        $this->member('08031234567');
        $this->member('002348051112233');

        $this->actingAs($admin)->postJson('/admin/broadcast/send', [
            'title' => 'Reminder',
            'message' => 'Renew before the 25th.',
            'channels' => ['sms'],
            'after' => 0,
        ])->assertOk()->assertJson(['sms_sent' => 2, 'sms_failed' => 0]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->isForm()
                && $request->header('x-api-key') === ['Bearer test-gateway-key']
                && $body['destination'] === ['+2348031234567', '+2348051112233']
                && $body['senderID'] === 'BELOVED'
                && $body['text'] === 'Renew before the 25th.'
                && $body['type'] === 'plain';
        });
    }

    public function test_the_fallback_gateway_refuses_to_claim_delivery(): void
    {
        $this->saveSettings(['site_name' => 'BelovedSubP']);

        $gateway = app(SmsGateway::class);

        $this->assertInstanceOf(NullSmsGateway::class, $gateway);
        $this->assertFalse($gateway->isConfigured());

        $result = $gateway->send(['+2348031234567', '+2348051112233'], 'Bulk notice');

        $this->assertSame(0, $result['sent']);
        $this->assertSame(2, $result['failed']);
        $this->assertStringContainsString('Add an API key', $result['message']);
    }
}
