<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class NinApi
{
    private string $apiKey;
    private string $baseUrl;
    private string $printEndpoint;
    private string $reportsEndpoint;
    private string $validationEndpoint;

    public function __construct()
    {
        $configuredKey = trim((string) setting('nin_api_key', ''));
        $fallbackKey = trim((string) config('services.nin.key', ''));
        $this->apiKey = $configuredKey !== '' ? $configuredKey : $fallbackKey;

        $configuredBase = trim((string) setting('nin_base_url', ''));
        $fallbackBase = trim((string) config('services.nin.base', 'https://confirmident.com.ng/api'));
        $base = $configuredBase !== '' ? $configuredBase : $fallbackBase;
        $this->baseUrl = rtrim($base, '/');

        $configuredPrint = trim((string) setting('nin_print_endpoint', ''));
        $fallbackPrint = trim((string) config('services.nin.print_endpoint', ''));
        $this->printEndpoint = $configuredPrint !== '' ? $configuredPrint : $fallbackPrint;

        $configuredReports = trim((string) setting('nin_reports_endpoint', ''));
        $fallbackReports = trim((string) config('services.nin.reports_endpoint', ''));
        $this->reportsEndpoint = $configuredReports !== '' ? $configuredReports : $fallbackReports;

        $configuredValidation = trim((string) setting('nin_validation_endpoint', ''));
        $fallbackValidation = trim((string) config('services.nin.validation_endpoint', ''));
        $this->validationEndpoint = $configuredValidation !== '' ? $configuredValidation : $fallbackValidation;
    }

    public function searchByNin(string $nin): array
    {
        return $this->post('/nin_search', ['nin' => trim($nin)]);
    }

    public function searchByPhone(string $phone): array
    {
        return $this->post('/nin_phone', ['phone' => trim($phone)]);
    }

    public function searchByDemography(string $firstname, string $lastname, string $dob, string $gender): array
    {
        return $this->post('/nin_demo', [
            'firstname' => trim($firstname),
            'lastname' => trim($lastname),
            'dob' => trim($dob),
            'gender' => trim($gender),
        ]);
    }

    public function supportsSlipPrint(): bool
    {
        return trim($this->printEndpoint) !== '';
    }

    public function supportsSlipReports(): bool
    {
        return trim($this->reportsEndpoint) !== '';
    }

    public function supportsValidation(): bool
    {
        return trim($this->validationEndpoint) !== '';
    }

    public function submitValidation(array $payload): array
    {
        if (!$this->supportsValidation()) {
            return [
                'success' => false,
                'message' => 'NIN validation endpoint is not configured.',
                'data' => [],
            ];
        }

        return $this->request('POST', $this->resolveEndpointUrl($this->validationEndpoint), $payload);
    }

    public function printSlip(array $payload): array
    {
        if (!$this->supportsSlipPrint()) {
            return [
                'success' => false,
                'message' => 'NIN slip print endpoint is not configured.',
            ];
        }

        return $this->request('POST', $this->resolveEndpointUrl($this->printEndpoint), $payload);
    }

    public function slipReports(): array
    {
        if (!$this->supportsSlipReports()) {
            return [
                'success' => false,
                'message' => 'NIN slip reports endpoint is not configured.',
                'data' => [],
            ];
        }

        return $this->request('GET', $this->resolveEndpointUrl($this->reportsEndpoint));
    }

    public function isSuccessful(array $response): bool
    {
        if (isset($response['success'])) {
            return (bool) $response['success'];
        }

        $status = strtolower(trim((string) ($response['status'] ?? '')));
        return $status === 'success' || $status === 'true';
    }

    public function message(array $response): string
    {
        $message = trim((string) ($response['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return 'Unable to complete NIN verification at this time.';
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $this->baseUrl . $path, $payload);
    }

    private function request(string $method, string $url, array $payload = []): array
    {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'message' => 'NIN API key is not configured.',
            ];
        }

        try {
            $client = Http::timeout(45)
                ->retry(1, 300)
                ->acceptJson()
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ]);

            if (strtoupper($method) === 'GET') {
                $response = $client->get($url, $payload);
            } else {
                $response = $client->post($url, $payload);
            }

            $json = $response->throw()->json();

            return is_array($json) ? $json : [
                'success' => false,
                'message' => 'Unexpected response from NIN provider.',
                'raw' => $json,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Could not connect to NIN provider.',
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'message' => 'NIN provider request failed.',
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
