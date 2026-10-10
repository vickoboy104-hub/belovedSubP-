<?php

namespace Tests\Unit;

use App\Services\JhTech\IpeClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JhTechIpeClientTest extends TestCase
{
    public function test_it_never_calls_the_provider_when_not_configured(): void
    {
        Http::fake();
        config()->set('services.jhtech.enabled', false);
        config()->set('services.jhtech.key', 'example-key');

        try {
            app(IpeClient::class)->submit('new_enrollment', 'ABCDEFGHIJKLMNO');
            $this->fail('Expected integration to refuse the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('JH Tech integration is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_submits_the_documented_payload_once(): void
    {
        config()->set('services.jhtech.enabled', true);
        config()->set('services.jhtech.key', 'example-key');
        Http::fake(['jhtechltd.com/Api/ipe_clearance' => Http::response([
            'success' => true,
            'tracking_id' => 'ABCDEFGHIJKLMNO',
            'status' => 'processing',
        ])]);

        $result = app(IpeClient::class)->submit('new_enrollment', 'ABCDEFGHIJKLMNO');

        $this->assertSame('processing', $result['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://jhtechltd.com/Api/ipe_clearance'
            && $request->method() === 'POST'
            && $request->hasHeader('api-key', 'example-key')
            && $request->data() === [
                'ipe_type' => 'new_enrollment',
                'tracking_id' => 'ABCDEFGHIJKLMNO',
                'method' => 'manual',
            ]);
    }
}
