<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FlutterwaveService
{
    private string $baseUrl;

    private string $secretKey;

    private string $secretHash;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.flutterwave.base_url', 'https://api.flutterwave.com'), '/');
        $this->secretKey = trim((string) config('services.flutterwave.secret_key', ''));
        $this->secretHash = trim((string) config('services.flutterwave.secret_hash', ''));
    }

    public function configured(): bool
    {
        return $this->secretKey !== '';
    }

    public function secretHashConfigured(): bool
    {
        return $this->secretHash !== '';
    }

    public function createPayment(array $payload): Response
    {
        return $this->request()
            ->post($this->baseUrl.'/v3/payments', $payload);
    }

    public function verifyTransaction(int|string $transactionId): Response
    {
        return $this->request()
            ->get($this->baseUrl.'/v3/transactions/'.rawurlencode((string) $transactionId).'/verify');
    }

    public function createVirtualAccount(array $payload): Response
    {
        return $this->request()
            ->post($this->baseUrl.'/v3/virtual-account-numbers', $payload);
    }

    public function validWebhook(Request $request): bool
    {
        if ($this->secretHash === '') {
            return false;
        }

        $payload = (string) $request->getContent();
        $verifHash = trim((string) $request->header('verif-hash', ''));
        if ($verifHash !== '' && hash_equals($this->secretHash, $verifHash)) {
            return true;
        }

        $signature = trim((string) $request->header('flutterwave-signature', ''));
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->secretHash);

        return hash_equals($expected, $signature);
    }

    private function request()
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->timeout(30);
    }
}
