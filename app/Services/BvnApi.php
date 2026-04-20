<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class BvnApi
{
    private string $apiKey;
    private string $baseUrl;
    private string $verifyEndpoint;
    private string $retrievePhoneEndpoint;
    private string $retrieveBmsEndpoint;
    private string $printEndpoint;

    public function __construct()
    {
        $configuredKey = trim((string) setting('bvn_api_key', ''));
        $this->apiKey = $configuredKey !== '' ? $configuredKey : trim((string) config('services.bvn.key', ''));

        $configuredBase = trim((string) setting('bvn_base_url', ''));
        $fallbackBase = trim((string) config('services.bvn.base', 'https://confirmident.com.ng/api'));
        $this->baseUrl = rtrim($configuredBase !== '' ? $configuredBase : $fallbackBase, '/');

        $this->verifyEndpoint = trim((string) setting('bvn_verify_endpoint', config('services.bvn.verify_endpoint', '/bvn_search')));
        $this->retrievePhoneEndpoint = trim((string) setting('bvn_retrieve_phone_endpoint', config('services.bvn.retrieve_phone_endpoint', '')));
        $this->retrieveBmsEndpoint = trim((string) setting('bvn_retrieve_bms_endpoint', config('services.bvn.retrieve_bms_endpoint', '')));
        $this->printEndpoint = trim((string) setting('bvn_print_endpoint', config('services.bvn.print_endpoint', '')));
    }

    public function verify(string $bvn): array
    {
        return $this->post($this->verifyEndpoint, ['bvn' => trim($bvn)]);
    }

    public function retrieveByPhone(string $phone): array
    {
        if (!$this->supportsRetrieveByPhone()) {
            return [
                'success' => false,
                'message' => 'BVN retrieve-by-phone endpoint is not configured.',
            ];
        }

        return $this->post($this->retrievePhoneEndpoint, ['phone' => trim($phone)]);
    }

    public function retrieveByBms(string $bmsNo, string $ticketId, ?string $agentCode = null): array
    {
        if (!$this->supportsRetrieveByBms()) {
            return [
                'success' => false,
                'message' => 'BVN retrieve-by-BMS endpoint is not configured.',
            ];
        }

        $payload = [
            'bms_no' => trim($bmsNo),
            'ticket_id' => trim($ticketId),
        ];

        if (trim((string) $agentCode) !== '') {
            $payload['agent_code'] = trim((string) $agentCode);
        }

        return $this->post($this->retrieveBmsEndpoint, $payload);
    }

    public function printSlip(array $payload): array
    {
        if (!$this->supportsPrint()) {
            return [
                'success' => false,
                'message' => 'BVN print endpoint is not configured.',
            ];
        }

        return $this->post($this->printEndpoint, $payload);
    }

    public function supportsRetrieveByPhone(): bool
    {
        return trim($this->retrievePhoneEndpoint) !== '';
    }

    public function supportsRetrieveByBms(): bool
    {
        return trim($this->retrieveBmsEndpoint) !== '';
    }

    public function supportsPrint(): bool
    {
        return trim($this->printEndpoint) !== '';
    }

    public function isSuccessful(array $response): bool
    {
        if (isset($response['success'])) {
            return (bool) $response['success'];
        }

        $status = strtolower(trim((string) ($response['status'] ?? '')));
        if (in_array($status, ['success', 'true', 'ok', '1'], true)) {
            return true;
        }

        $merged = strtolower(trim(implode(' ', array_filter([
            (string) ($response['message'] ?? ''),
            (string) ($response['description'] ?? ''),
            (string) ($response['api_response'] ?? ''),
        ]))));

        if (str_contains($merged, 'success')) {
            return true;
        }

        if (str_contains($merged, 'failed') || str_contains($merged, 'error')) {
            return false;
        }

        return false;
    }

    public function message(array $response): string
    {
        foreach (['message', 'description', 'api_response'] as $key) {
            $value = trim((string) ($response[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Unable to complete BVN request at this time.';
    }

    private function post(string $endpoint, array $payload): array
    {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'message' => 'BVN API key is not configured.',
            ];
        }

        if (trim($endpoint) === '') {
            return [
                'success' => false,
                'message' => 'BVN endpoint is not configured.',
            ];
        }

        return $this->request('POST', $this->resolveEndpointUrl($endpoint), $payload);
    }

    private function request(string $method, string $url, array $payload = []): array
    {
        try {
            $client = Http::timeout(45)
                ->retry(1, 300)
                ->acceptJson()
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ]);

            $response = strtoupper($method) === 'GET'
                ? $client->get($url, $payload)
                : $client->post($url, $payload);

            $json = $response->throw()->json();

            return is_array($json) ? $json : [
                'success' => false,
                'message' => 'Unexpected response from BVN provider.',
                'raw' => $json,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Could not connect to BVN provider.',
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'message' => 'BVN provider request failed.',
                'error' => $e->getMessage(),
                'response' => optional($e->response)->json(),
            ];
        }
    }

    private function resolveEndpointUrl(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return $this->baseUrl;
        }

        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        $path = str_starts_with($endpoint, '/') ? $endpoint : '/' . $endpoint;

        return $this->baseUrl . $path;
    }
}
