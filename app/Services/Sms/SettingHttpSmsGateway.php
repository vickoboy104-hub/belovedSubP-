<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The gateway-ready sender. Everything a provider needs lives in settings, so
 * connecting one is a matter of pasting a key and a URL, never a code change.
 *
 * Two shapes cover the Nigerian aggregators: a JSON body, and the form-encoded
 * body older panels still use. `sms_param_map` renames our generic fields to
 * whatever the provider calls them, so `to` can become `destination`, `msisdn`,
 * or `to[]` without touching this file again.
 */
class SettingHttpSmsGateway implements SmsGateway
{
    private const CHUNK = 50;

    public function name(): string
    {
        return trim((string) setting('sms_driver', '')) ?: 'sms';
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->key() !== '' && $this->sender() !== '';
    }

    /**
     * @param  list<string>  $numbers
     * @return array{sent: int, failed: int, message: string}
     */
    public function send(array $numbers, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['sent' => 0, 'failed' => count($numbers), 'message' => 'No SMS gateway is configured yet.'];
        }

        $sent = 0;
        $failed = 0;
        $lastError = '';

        foreach (array_chunk($numbers, self::CHUNK) as $chunk) {
            $payload = $this->payload($chunk, $message);

            try {
                $request = Http::timeout(30);

                $authHeader = trim((string) setting('sms_auth_header', 'Authorization'));
                if (strcasecmp($authHeader, 'none') !== 0) {
                    $request = $request->withHeaders([
                        $authHeader => str_contains($authHeader, 'Basic')
                            ? 'Basic '.base64_encode($this->key())
                            : 'Bearer '.$this->key(),
                    ]);
                }

                $response = $this->usesFormBody()
                    ? $request->asForm()->post($this->url(), $payload)
                    : $request->asJson()->post($this->url(), $payload);

                if ($response->successful() && $this->providerAgrees($response->json())) {
                    $sent += count($chunk);
                    continue;
                }

                $failed += count($chunk);
                $lastError = trim((string) ($response->json('message')
                    ?? $response->json('msg')
                    ?? $response->body())) ?: 'The gateway rejected this batch.';
            } catch (Throwable $e) {
                $failed += count($chunk);
                $lastError = $e->getMessage();
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'message' => $lastError !== '' ? $lastError : ($sent > 0 ? 'Delivered to the gateway.' : ''),
        ];
    }

    /**
     * @param  list<string>  $numbers
     * @return array<string, mixed>
     */
    private function payload(array $numbers, string $message): array
    {
        $map = $this->paramMap();
        $body = [
            $map['to'] ?? 'to' => count($numbers) === 1 ? $numbers[0] : $numbers,
            $map['from'] ?? 'from' => $this->sender(),
            $map['message'] ?? 'message' => $message,
        ];

        foreach (json_decode((string) setting('sms_extra_params', ''), true) ?: [] as $field => $value) {
            $body[(string) $field] = $value;
        }

        return $body;
    }

    /**
     * A 200 alone is not delivery: many panels answer 200 with a failure code
     * inside the body, so a configured success field has to match.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function providerAgrees(?array $json): bool
    {
        if ($json === null) {
            return false;
        }

        $field = trim((string) setting('sms_success_field', ''));
        if ($field === '') {
            return true;
        }

        $actual = data_get($json, $field);
        if ($actual === null) {
            return false;
        }

        $expected = trim((string) setting('sms_success_value', '0'));

        return strcasecmp((string) $actual, $expected) === 0
            || filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /** @return array<string, string> */
    private function paramMap(): array
    {
        $decoded = json_decode((string) setting('sms_param_map', ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function usesFormBody(): bool
    {
        return strcasecmp((string) setting('sms_body_format', 'json'), 'form') === 0;
    }

    private function url(): string
    {
        return $this->fromSettingsOrConfig('sms_endpoint', 'services.sms.endpoint');
    }

    private function sender(): string
    {
        return $this->fromSettingsOrConfig('sms_sender_id', 'services.sms.sender');
    }

    /** Admin Settings wins, so a .env value never shadows what the owner typed. */
    private function fromSettingsOrConfig(string $settingKey, string $configKey): string
    {
        $fromSetting = trim((string) setting($settingKey, ''));

        return $fromSetting !== '' ? $fromSetting : trim((string) config($configKey, ''));
    }

    private function key(): string
    {
        return $this->fromSettingsOrConfig('sms_api_key', 'services.sms.key');
    }
}
