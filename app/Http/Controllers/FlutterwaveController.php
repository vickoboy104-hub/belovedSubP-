<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FlutterwaveService;
use App\Services\WalletDepositSync;
use App\Services\WalletFundingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwaveController extends Controller
{
    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly WalletFundingService $funding,
        private readonly WalletDepositSync $deposits,
    ) {
    }

    public function initialize(Request $request)
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:100'],
        ]);

        $user = auth()->user();

        if (!$user || !$user->wallet) {
            return back()->with('error', 'Wallet not found for this user.');
        }

        if (!$this->flutterwave->configured()) {
            return back()->with('error', 'Flutterwave is not configured yet. Please contact admin.');
        }

        $amountKobo = (int) round(((float) $request->amount) * 100);
        $feeNaira = (float) setting('wallet_funding_fee', 50);
        $feeKobo = max(0, (int) round($feeNaira * 100));

        if ($amountKobo <= $feeKobo) {
            return back()->with('error', 'Amount must be greater than the funding fee.');
        }

        $creditKobo = $amountKobo - $feeKobo;
        $reference = 'FLW_'.Str::upper(Str::random(12));

        $tx = WalletTransaction::create([
            'wallet_id' => $user->wallet->id,
            'type' => 'credit',
            'amount' => $amountKobo,
            'reference' => $reference,
            'status' => 'pending',
            'channel' => 'flutterwave',
            'description' => 'Wallet funding via Flutterwave',
            'meta' => [
                'amount_naira' => (float) $request->amount,
                'fee_naira' => $feeNaira,
                'fee_kobo' => $feeKobo,
                'credited_kobo' => $creditKobo,
                'email' => $user->email,
            ],
        ]);

        try {
            $response = $this->flutterwave->createPayment([
                'tx_ref' => $reference,
                'amount' => number_format((float) $request->amount, 2, '.', ''),
                'currency' => 'NGN',
                'redirect_url' => route('flutterwave.callback'),
                'customer' => [
                    'email' => (string) $user->email,
                    'name' => trim((string) ($user->name ?: ($user->first_name.' '.$user->last_name))),
                    'phonenumber' => (string) ($user->phone ?? ''),
                ],
                'customizations' => [
                    'title' => site_name(),
                    'description' => 'Wallet funding',
                ],
                'meta' => [
                    'wallet_id' => $user->wallet->id,
                    'user_id' => $user->id,
                    'purpose' => 'wallet_funding',
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Flutterwave initialize failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'reference' => $reference,
                ]);

                $this->markInitializationFailed($tx, 'Payment initialization failed at Flutterwave.', [
                    'gateway_status' => $response->status(),
                    'gateway_body' => $response->json() ?: $response->body(),
                ]);

                return back()->with('error', 'Payment initialization failed. Please try again.');
            }

            $data = (array) $response->json('data', []);
            $link = trim((string) ($data['link'] ?? ''));

            if ($link === '') {
                Log::error('Flutterwave initialize missing payment link', [
                    'response' => $response->json(),
                    'reference' => $reference,
                ]);

                $this->markInitializationFailed($tx, 'Payment link was not returned by Flutterwave.', [
                    'gateway_response' => $response->json(),
                ]);

                return back()->with('error', 'Payment initialization failed. Please try again.');
            }

            return redirect()->away($link);
        } catch (\Throwable $e) {
            Log::error('Flutterwave initialize exception', [
                'message' => $e->getMessage(),
                'reference' => $reference,
            ]);

            $this->markInitializationFailed($tx, 'Payment initialization exception.', [
                'exception_message' => $e->getMessage(),
            ]);

            return back()->with('error', 'Payment could not start. Please try again.');
        }
    }

    public function callback(Request $request)
    {
        $reference = trim((string) $request->query('tx_ref', ''));
        $transactionId = trim((string) $request->query('transaction_id', ''));
        $status = strtolower(trim((string) $request->query('status', '')));

        if ($reference === '') {
            return redirect()->route('wallet.fund')->with('error', 'Payment reference not found.');
        }

        if (!$this->flutterwave->configured()) {
            return redirect()->route('wallet.fund')->with('error', 'Flutterwave is not configured yet.');
        }

        $tx = WalletTransaction::where('reference', $reference)->first();
        if (!$tx) {
            return redirect()->route('wallet.fund')->with('error', 'Transaction not found.');
        }

        if ($tx->status === 'success') {
            return redirect()->route('wallet.fund')->with('success', 'Wallet already funded.');
        }

        if ($transactionId === '' || in_array($status, ['cancelled', 'failed'], true)) {
            $tx->update(['status' => 'failed']);

            return redirect()->route('wallet.fund')->with('error', 'Payment was not completed.');
        }

        try {
            $verify = $this->flutterwave->verifyTransaction($transactionId);

            if (!$verify->successful()) {
                Log::warning('Flutterwave verify failed', [
                    'status' => $verify->status(),
                    'body' => $verify->body(),
                    'reference' => $reference,
                    'transaction_id' => $transactionId,
                ]);

                $tx->update(['status' => 'failed']);

                return redirect()->route('wallet.fund')->with('error', 'Verification failed. If you were debited, contact support.');
            }

            $data = (array) $verify->json('data', []);
            if (!$this->verifiedSuccessfulFunding($data, $tx)) {
                $tx->update([
                    'status' => 'failed',
                    'meta' => array_merge($tx->meta ?? [], [
                        'gateway_response' => $data['processor_response'] ?? ($data['status'] ?? null),
                    ]),
                ]);

                $reason = (string) ($data['processor_response'] ?? 'Payment was not successful.');

                return redirect()->route('wallet.fund')->with('error', $reason);
            }

            if (!$tx->wallet) {
                Log::warning('Flutterwave checkout returned for a funding request with no wallet.', [
                    'reference' => $reference,
                ]);

                return redirect()
                    ->route('wallet.fund')
                    ->with('error', 'This deposit could not be attached to a wallet. Contact support with reference '.$reference.'.');
            }

            $creditedKobo = $this->funding->creditFlutterwaveCharge(
                $tx->wallet,
                $data,
                (string) ($tx->channel ?: 'flutterwave'),
                'checkout_return',
            );

            $feeNaira = (float) setting('wallet_funding_fee', 50);
            $feeText = $feeNaira > 0 ? ' (N'.number_format($feeNaira, 2).' fee deducted)' : '';

            return redirect()
                ->route('wallet.fund')
                ->with('success', $creditedKobo > 0 ? 'Wallet funded successfully'.$feeText : 'Wallet already funded.');
        } catch (\Throwable $e) {
            Log::error('Flutterwave callback exception', [
                'message' => $e->getMessage(),
                'reference' => $reference,
                'transaction_id' => $transactionId,
            ]);

            return redirect()->route('wallet.fund')->with('error', 'Verification error. If you were debited, contact support.');
        }
    }

    public function webhook(Request $request)
    {
        if (!$this->flutterwave->secretHashConfigured()) {
            Log::error('Flutterwave webhook rejected. Secret hash is not configured.');

            return response()->json(['ok' => false], 500);
        }

        if (!$this->flutterwave->validWebhook($request)) {
            Log::warning('Flutterwave webhook signature mismatch.');

            return response()->json(['ok' => false], 401);
        }

        $event = $request->json()->all();
        if (!is_array($event)) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $eventName = strtolower(trim((string) ($event['event'] ?? ($event['type'] ?? ''))));

            if ($eventName === 'charge.completed') {
                $data = (array) ($event['data'] ?? []);
                if ($this->flutterwave->isSuccessfulChargeStatus($data['status'] ?? '')) {
                    $eventType = strtoupper(trim((string) ($event['event.type'] ?? '')));
                    $paymentType = strtolower(trim((string) ($data['payment_type'] ?? data_get($data, 'payment_method.type', ''))));
                    $meta = (array) ($event['meta_data'] ?? []);

                    if ($eventType === 'BANK_TRANSFER_TRANSACTION' || $paymentType === 'bank_transfer') {
                        $this->handleVirtualAccountTransfer($data, $meta);
                    } else {
                        $this->handleSuccessfulCharge($data);
                    }
                }
            } else {
                // Anything else (a refund, a subscription, a failure) is reported
                // for the record but must never move a wallet balance here.
                Log::info('Flutterwave webhook carried an event with nothing to bank.', [
                    'event' => $eventName,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Flutterwave webhook handling failed.', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Asks Flutterwave directly what has been paid for this customer. A webhook
     * only arrives when Flutterwave's servers can reach this site, so a machine
     * on localhost, behind a VPN, or with the wrong URL pasted into the
     * Flutterwave dashboard would otherwise leave paid-for deposits unbanked.
     */
    public function checkDeposits(Request $request)
    {
        $result = $this->deposits->sync($request->user(), onDemand: true);

        if ($result['credited_kobo'] > 0) {
            Log::info('Deposit credited from a customer-initiated check.', [
                'user_id' => $request->user()->id,
                'credited_kobo' => $result['credited_kobo'],
            ]);
        }

        return back()->with('deposit_check', $result);
    }

    private function verifiedSuccessfulFunding(array $data, WalletTransaction $tx): bool
    {
        $status = (string) ($data['status'] ?? '');
        $txRef = trim((string) ($data['tx_ref'] ?? ''));
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $chargedAmount = (float) ($data['charged_amount'] ?? ($data['amount'] ?? 0));
        $expectedAmount = ((int) $tx->amount) / 100;

        return $this->flutterwave->isSuccessfulChargeStatus($status)
            && $txRef === (string) $tx->reference
            && $currency === 'NGN'
            && $chargedAmount >= $expectedAmount;
    }

    private function handleSuccessfulCharge(array $data): void
    {
        $verifiedData = $this->resolveVerifiedWebhookChargeData($data);
        $reference = trim((string) ($verifiedData['tx_ref'] ?? ($data['tx_ref'] ?? '')));

        if ($reference === '') {
            return;
        }

        $tx = WalletTransaction::query()->where('reference', $reference)->first();
        if (!$tx || !$tx->wallet) {
            Log::warning('Flutterwave checkout webhook ignored because it did not match a wallet funding request.', [
                'tx_ref' => $reference,
                'customer_email' => trim((string) data_get($verifiedData, 'customer.email', data_get($data, 'customer.email', ''))),
                'flutterwave_charge_id' => trim((string) ($verifiedData['id'] ?? '')),
                'flutterwave_id' => trim((string) ($verifiedData['flw_ref'] ?? '')),
            ]);

            return;
        }

        $this->funding->creditFlutterwaveCharge(
            $tx->wallet,
            $verifiedData,
            (string) ($tx->channel ?: 'flutterwave'),
            'webhook',
            ['webhook_event' => 'charge.completed'],
        );
    }

    private function handleVirtualAccountTransfer(array $data, array $meta): void
    {
        $verifiedData = $this->resolveVerifiedWebhookChargeData($data);
        $txRef = trim((string) ($verifiedData['tx_ref'] ?? ($data['tx_ref'] ?? '')));
        $customerEmail = trim((string) data_get($verifiedData, 'customer.email', data_get($data, 'customer.email', '')));
        $virtualAccountNumber = trim((string) (
            $this->funding->chargeAccountNumber($verifiedData)
            ?: $data['account_number']
            ?: data_get($data, 'meta.authorization.transfer_account')
            ?: data_get($data, 'authorization.transfer_account')
            ?: data_get($meta, 'beneficiaryaccountnumber')
            ?: data_get($meta, 'accountnumber')
            ?: ''
        ));

        $user = null;
        if ($txRef !== '') {
            $user = User::query()->where('virtual_account_metadata->tx_ref', $txRef)->first();
        }
        if (!$user && $txRef !== '') {
            $user = User::query()->where('virtual_account_metadata->temporary_virtual_account->tx_ref', $txRef)->first();
        }
        if (!$user && $txRef !== '') {
            // A one-time account records the deposit it is waiting for, so the
            // transfer can be traced back through that request.
            $user = WalletTransaction::query()
                ->where('reference', $txRef)
                ->where('type', 'credit')
                ->first()?->wallet?->user;
        }
        if (!$user && $virtualAccountNumber !== '') {
            $user = User::query()->where('virtual_account_number', $virtualAccountNumber)->first();
        }
        if (!$user && $virtualAccountNumber !== '') {
            $user = User::query()->where('virtual_account_metadata->temporary_virtual_account->account_number', $virtualAccountNumber)->first();
        }
        if (!$user && $virtualAccountNumber !== '') {
            // One-time accounts get replaced whenever a customer asks for a fresh
            // one, and the transfer for the old number still arrives. The funding
            // request that was told about that number is the only record of who
            // the money belongs to.
            $user = WalletTransaction::query()
                ->where('type', 'credit')
                ->where('meta->virtual_account_number', $virtualAccountNumber)
                ->first()?->wallet?->user;
        }
        if (!$user || !$user->wallet) {
            Log::warning('Flutterwave virtual account transfer user not found.', [
                'tx_ref' => $txRef,
                'virtual_account_number' => $virtualAccountNumber,
                'customer_email' => $customerEmail,
                'flutterwave_charge_id' => trim((string) ($verifiedData['id'] ?? '')),
                'flutterwave_id' => trim((string) ($verifiedData['flw_ref'] ?? '')),
                'data' => $verifiedData,
                'meta' => $meta,
            ]);

            return;
        }

        $this->funding->creditFlutterwaveCharge(
            $user->wallet,
            $verifiedData,
            'flutterwave_virtual_account',
            'webhook',
            [
                'webhook_event' => 'BANK_TRANSFER_TRANSACTION',
                'flutterwave_meta' => $meta,
                'virtual_account_number' => $virtualAccountNumber,
            ],
        );
    }

    private function resolveVerifiedWebhookChargeData(array $data): array
    {
        $transactionId = trim((string) ($data['id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException('Flutterwave webhook did not include a charge id.');
        }

        $verify = $this->flutterwave->verifyTransaction($transactionId);
        if (!$verify->successful()) {
            Log::warning('Flutterwave webhook verification failed.', [
                'transaction_id' => $transactionId,
                'status' => $verify->status(),
                'body' => $verify->json() ?: $verify->body(),
            ]);

            throw new \RuntimeException('Flutterwave webhook verification failed.');
        }

        $verifiedData = (array) $verify->json('data', []);
        if (!$this->flutterwave->isSuccessfulChargeStatus($verifiedData['status'] ?? '')) {
            Log::warning('Flutterwave webhook verification returned a non-success status.', [
                'transaction_id' => $transactionId,
                'verified_data' => $verifiedData,
            ]);

            throw new \RuntimeException('Flutterwave webhook verification returned a non-success status.');
        }

        $currency = strtoupper((string) ($verifiedData['currency'] ?? ($data['currency'] ?? '')));
        if ($currency !== 'NGN') {
            Log::warning('Flutterwave webhook ignored due to unexpected currency.', [
                'transaction_id' => $transactionId,
                'currency' => $currency,
                'verified_data' => $verifiedData,
            ]);

            throw new \RuntimeException('Flutterwave webhook currency mismatch.');
        }

        return $verifiedData;
    }

    private function markInitializationFailed(WalletTransaction $tx, string $reason, array $extraMeta = []): void
    {
        $meta = array_merge($tx->meta ?? [], [
            'initialization_failed_at' => now()->toISOString(),
            'initialization_error' => $reason,
        ], $extraMeta);

        $tx->update([
            'status' => 'failed',
            'meta' => $meta,
        ]);
    }
}
