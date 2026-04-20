<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GsubzApi
{
    private string $apiKey;
    private string $baseUrl;
    private string $provider;

    public function __construct()
    {
        $this->provider = trim((string) setting('provider', 'gsubz'));

        $defaultKey = (string) config('services.gsubz.key', '');
        $defaultBase = (string) config('services.gsubz.base', 'https://api.gsubz.com');

        if ($this->provider === 'alt') {
            $this->apiKey = trim((string) setting('provider_alt_api_key', (string) config('services.alt.key', '')));
            $this->baseUrl = rtrim((string) setting('provider_alt_base_url', (string) config('services.alt.base', $defaultBase)), '/');
        } else {
            $this->apiKey = trim((string) setting('provider_gsubz_api_key', $defaultKey));
            $this->baseUrl = rtrim((string) setting('provider_gsubz_base_url', $defaultBase), '/');
        }

        if ($this->baseUrl === '') {
            $this->baseUrl = rtrim($defaultBase, '/');
        }

        // Normalize base URL to avoid double "/api" when building endpoints.
        if (str_ends_with($this->baseUrl, '/api')) {
            $this->baseUrl = substr($this->baseUrl, 0, -4);
        }
    }

    public function plans(string $serviceId): array
    {
        try {
            $url = $this->baseUrl . '/api/plans';

            $resp = Http::timeout(30)
                ->retry(2, 300)
                ->acceptJson()
                ->get($url, ['service' => $serviceId])
                ->throw()
                ->json();

            // ✅ Case 1: { plans: [...] }
            if (is_array($resp) && isset($resp['plans']) && is_array($resp['plans'])) {
                return [
                    'ok'    => true,
                    'plans' => $resp['plans'],
                    'raw'   => $resp,
                ];
            }

            // ✅ Case 2: { list: [...] }  <-- THIS IS WHAT YOU HAVE
            if (is_array($resp) && isset($resp['list']) && is_array($resp['list'])) {
                // Normalize to a standard shape your frontend can use
                $plans = array_map(function ($p) {
                    return [
                        'code'  => $p['value'] ?? null,               // e.g. gotv-max
                        'price' => $p['price'] ?? null,               // e.g. "8500.00"
                        'name'  => $p['display_name'] ?? ($p['value'] ?? ''),
                        // keep original fields too (optional)
                        'value' => $p['value'] ?? null,
                        'display_name' => $p['display_name'] ?? null,
                    ];
                }, $resp['list']);

                return [
                    'ok'    => true,
                    'plans' => $plans,
                    'raw'   => $resp,
                ];
            }

            // ✅ Case 3: bare array [...]
            if (is_array($resp) && array_is_list($resp)) {
                return [
                    'ok'    => true,
                    'plans' => $resp,
                    'raw'   => $resp,
                ];
            }

            return [
                'ok'      => false,
                'plans'   => [],
                'raw'     => $resp,
                'message' => $resp['message'] ?? 'No plans returned from provider.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'plans'   => [],
                'message' => 'Could not load plans from GSUBZ.',
                'error'   => $e->getMessage(),
            ];
        }
    }


    public function pay(array $payload): array
    {
        try {
            $url = $this->baseUrl . '/api/pay/';

            $body = array_merge($payload, [
                'api' => $this->apiKey,
            ]);

            $json = $this->client()
                ->asForm()
                ->post($url, $body)
                ->throw()
                ->json();

            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'message' => 'Unexpected response from GSUBZ (pay).',
                    'raw' => $json,
                ];
            }

            if (isset($json['content']) && is_array($json['content'])) {
                $json = array_merge($json, $json['content']);
            }

            return $json;

        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Could not connect to GSUBZ (pay).',
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'ok' => false,
                'message' => 'GSUBZ request failed (pay).',
                'error' => $e->getMessage(),
                'response' => optional($e->response)->json(),
            ];
        }
    }

    public function balance(): array
    {
        try {
            $url = $this->baseUrl . '/api/balance/';

            // Try POST first (most common in doc), fallback to GET.
            $resp = $this->client()->asForm()->post($url, [
                'api' => $this->apiKey,
            ]);

            if ($resp->successful()) {
                $json = $resp->json();
                return is_array($json) ? $json : ['ok' => false, 'raw' => $json];
            }

            $json = $this->client()
                ->get($url, [
                    'api' => $this->apiKey,
                ])
                ->throw()
                ->json();

            return is_array($json) ? $json : ['ok' => false, 'raw' => $json];
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Could not connect to GSUBZ (balance).',
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'ok' => false,
                'message' => 'GSUBZ request failed (balance).',
                'error' => $e->getMessage(),
                'response' => optional($e->response)->json(),
            ];
        }
    }

    /**
     * Extract provider balance as a float (naira) from a balance() response.
     * Returns null if balance could not be determined.
     */
    public function balanceNaira(array $resp): ?float
    {
        $candidates = [
            $resp['balance'] ?? null,
            $resp['Balance'] ?? null,
            $resp['data']['balance'] ?? null,
            $resp['content']['balance'] ?? null,
            $resp['content']['Balance'] ?? null,
        ];

        foreach ($candidates as $val) {
            if ($val === null) continue;

            if (is_numeric($val)) return (float) $val;

            if (is_string($val)) {
                $clean = preg_replace('/[^0-9.]/', '', $val);
                if (is_numeric($clean)) return (float) $clean;
            }
        }

        return null;
    }

    public function verify(string $requestId): array
    {
        try {
            $url = $this->baseUrl . '/api/verify/';

            $json = $this->client()
                ->asForm()
                ->post($url, [
                    'api'       => $this->apiKey,
                    'requestID' => $requestId,
                ])
                ->throw()
                ->json();

            return is_array($json) ? $json : ['ok' => false, 'raw' => $json];
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Could not connect to GSUBZ (verify).',
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'ok' => false,
                'message' => 'GSUBZ request failed (verify).',
                'error' => $e->getMessage(),
                'response' => optional($e->response)->json(),
            ];
        }
    }

    public function socialPlans(): array
    {
        try {
            $url = $this->baseUrl . '/socially/plan/';

            $json = Http::timeout(45)
                ->retry(1, 300)
                ->acceptJson()
                ->get($url)
                ->throw()
                ->json();

            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'plans' => [],
                    'message' => 'Unexpected social plans response.',
                    'raw' => $json,
                ];
            }

            $list = $json['list'] ?? [];
            if (!is_array($list)) {
                return [
                    'ok' => false,
                    'plans' => [],
                    'message' => 'No social plans returned.',
                    'raw' => $json,
                ];
            }

            return [
                'ok' => true,
                'plans' => $list,
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'plans' => [],
                'message' => 'Could not load social plans from provider.',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isSuccessful(array $resp): bool
    {
        $content = is_array($resp['content'] ?? null) ? $resp['content'] : [];
        $merged = array_merge($resp, $content);
        $normalized = [];
        foreach ($merged as $k => $v) {
            if (is_string($k)) {
                $normalized[strtolower($k)] = $v;
            }
        }

        $statusRaw = $normalized['status'] ?? ($merged['status'] ?? null);
        $status = strtolower(trim((string) ($statusRaw ?? '')));
        $desc = strtolower(trim((string) ($normalized['description'] ?? ($merged['description'] ?? ''))));
        $apiResponse = strtolower(trim((string) ($normalized['api_response'] ?? ($merged['api_response'] ?? ''))));
        $message = strtolower(trim((string) ($normalized['message'] ?? ($merged['message'] ?? ''))));
        $combined = trim($status . ' ' . $desc . ' ' . $apiResponse . ' ' . $message);

        $code = (int) ($normalized['code'] ?? ($merged['code'] ?? 0));

        // Explicit failure signals from provider responses.
        if (
            str_contains($combined, 'failed')
            || str_contains($combined, 'not successful')
            || str_contains($combined, 'insufficient')
            || str_contains($combined, 'error')
        ) {
            return false;
        }

        if (
            $statusRaw === true
            || $statusRaw === 1
            || $status === '1'
            || $status === 'true'
        ) {
            return true;
        }

        // Explicit success signals.
        if (
            str_contains($combined, 'transaction_successful')
            || str_contains($combined, 'transaction successful')
            || str_contains($combined, 'successful')
            || str_contains($combined, 'success')
        ) {
            return true;
        }

        if ($code === 200) {
            return true;
        }

        // Some successful responses omit clear status text but include transaction identifiers.
        if (
            !empty($merged['transactionID'])
            || !empty($normalized['transactionid'])
        ) {
            return true;
        }

        return false;
    }

    public function message(array $resp): string
    {
        if (!empty($resp['api_response'])) return (string) $resp['api_response'];
        if (!empty($resp['description'])) return (string) $resp['description'];
        if (!empty($resp['message'])) return (string) $resp['message'];

        $code = (int) ($resp['code'] ?? 0);
        $map = [
            204 => 'Required content not sent.',
            206 => 'Invalid content.',
            401 => 'Invalid plan.',
            402 => 'Insufficient provider balance.',
            404 => 'Content not found.',
            405 => 'Request method not POST.',
            406 => 'Service disabled.',
            502 => 'Gateway error.',
        ];

        if ($code !== 0 && isset($map[$code])) return $map[$code];
        if (!empty($resp['code'])) return "Transaction failed (Code: {$resp['code']}).";
        return "Transaction failed.";
    }

    private function client()
    {
        return Http::timeout(30)
            ->retry(2, 300)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ]);
    }
}
