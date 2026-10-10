<?php

namespace App\Services\JhTech;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class IpeClient
{
    /**
     * Submit one IPE clearance request. A transport error leaves the provider's
     * charge state unknown, so callers must never automatically retry it.
     *
     * @return array<string, mixed>
     */
    public function submit(string $type, string $trackingId, string $method = 'manual'): array
    {
        if (! in_array($type, ['new_enrollment'], true)
            || ! preg_match('/^[A-Za-z0-9]{15}$/D', $trackingId)
            || ! in_array($method, ['manual', 'auto'], true)) {
            throw new InvalidArgumentException('Invalid IPE request.');
        }

        $key = config('services.jhtech.key');
        if (! config('services.jhtech.enabled') || ! is_string($key) || trim($key) === '') {
            throw new RuntimeException('JH Tech integration is not configured.');
        }

        // The host is fixed so a misconfigured URL cannot receive the API key.
        $response = Http::acceptJson()
            ->withHeaders(['api-key' => $key])
            ->timeout(30)
            ->post('https://jhtechltd.com/Api/ipe_clearance', [
                'ipe_type' => $type,
                'tracking_id' => $trackingId,
                'method' => $method,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('JH Tech request was not confirmed; check its status before retrying.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('JH Tech returned an unexpected response; check its status before retrying.');
        }

        return $body;
    }
}
