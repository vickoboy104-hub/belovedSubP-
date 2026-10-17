<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    /**
     * Flutterwave's own record of what has been paid, filtered by the reference
     * we sent or the status we want. This is how a deposit is found when no
     * webhook ever reached the site.
     *
     * @param  array<string, mixed>  $params
     */
    public function findTransactions(array $params): Response
    {
        return $this->request()
            ->get($this->baseUrl.'/v3/transactions', $params);
    }

    public function isSuccessfulChargeStatus(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['success', 'successful', 'succeeded'], true);
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
        // A 404 from Flutterwave means "there is no such charge", which no amount
        // of retrying will fix and which every caller already checks for with
        // successful(). Only a dropped connection or a gateway problem is worth a
        // second attempt - and none of them may turn into an exception thrown in
        // the middle of a customer's wallet page.
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->connectTimeout(15)
            ->retry(
                2,
                400,
                when: fn (Throwable $exception): bool => $this->isWorthAnotherAttempt($exception),
                throw: false,
            )
            ->timeout(30);
    }

    private function isWorthAnotherAttempt(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->getResponse()?->status();

            return $status === null || $status === 429 || $status >= 500;
        }

        return true;
    }
}
