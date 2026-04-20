<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use App\Services\FlutterwaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlutterwaveController extends Controller
{
    public function __construct(
        private readonly FlutterwaveService $flutterwave,
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
                    'title' => (string) setting('site_name', config('app.name', 'BelovedSubP')),
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

            DB::transaction(function () use ($tx, $data) {
                $updatedMeta = array_merge($tx->meta ?? [], [
                    'flutterwave_id' => $data['id'] ?? null,
                    'flw_ref' => $data['flw_ref'] ?? null,
                    'gateway_response' => $data['processor_response'] ?? null,
                    'paid_at' => $data['created_at'] ?? now()->toISOString(),
                    'channel' => $data['payment_type'] ?? 'flutterwave',
                    'flutterwave_payload' => $data,
                ]);

                $tx->update([
                    'status' => 'success',
                    'meta' => $updatedMeta,
                ]);

                $wallet = $tx->wallet()->lockForUpdate()->first();
                if (!$wallet) {
                    return;
                }

                $feeKobo = (int) ($updatedMeta['fee_kobo'] ?? 0);
                $creditKobo = (int) ($updatedMeta['credited_kobo'] ?? max(0, ((int) $tx->amount) - $feeKobo));
                $wallet->balance += $creditKobo;
                $wallet->save();

                $fundedUser = $wallet->user()->lockForUpdate()->first();
                if ($fundedUser) {
                    $this->markReferralQualified($fundedUser);
                    $this->notifyWalletFunding(
                        $fundedUser,
                        $creditKobo,
                        (string) $tx->reference,
                        (string) ($tx->channel ?? 'flutterwave'),
                        (int) ($wallet->balance - $creditKobo),
                        (int) $wallet->balance,
                    );
                }
            });

            $feeNaira = (float) setting('wallet_funding_fee', 50);
            $feeText = $feeNaira > 0 ? ' (N'.number_format($feeNaira, 2).' fee deducted)' : '';

            return redirect()->route('wallet.fund')->with('success', 'Wallet funded successfully'.$feeText);
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
            if (($event['event'] ?? '') === 'charge.completed') {
                $data = (array) ($event['data'] ?? []);
                if (($data['status'] ?? '') === 'successful') {
                    $eventType = strtoupper(trim((string) ($event['event.type'] ?? '')));
                    $paymentType = strtolower(trim((string) ($data['payment_type'] ?? '')));
                    $meta = (array) ($event['meta_data'] ?? []);

                    if ($eventType === 'BANK_TRANSFER_TRANSACTION' || $paymentType === 'bank_transfer') {
                        $this->handleVirtualAccountTransfer($data, $meta);
                    } else {
                        $this->handleSuccessfulCharge($data);
                    }
                }
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

    private function verifiedSuccessfulFunding(array $data, WalletTransaction $tx): bool
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        $txRef = trim((string) ($data['tx_ref'] ?? ''));
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $chargedAmount = (float) ($data['charged_amount'] ?? ($data['amount'] ?? 0));
        $expectedAmount = ((int) $tx->amount) / 100;

        return $status === 'successful'
            && $txRef === (string) $tx->reference
            && $currency === 'NGN'
            && $chargedAmount >= $expectedAmount;
    }

    private function handleSuccessfulCharge(array $data): void
    {
        $reference = trim((string) ($data['tx_ref'] ?? ''));
        $amountKobo = (int) round(((float) ($data['charged_amount'] ?? $data['amount'] ?? 0)) * 100);
        $flutterwaveId = trim((string) ($data['id'] ?? ''));
        $customerEmail = trim((string) data_get($data, 'customer.email', ''));

        if ($reference === '' || $amountKobo <= 0) {
            return;
        }

        $tx = WalletTransaction::query()->where('reference', $reference)->first();
        if ($tx) {
            if ($tx->status === 'success') {
                return;
            }

            DB::transaction(function () use ($tx, $data, $amountKobo, $flutterwaveId) {
                $wallet = $tx->wallet()->lockForUpdate()->first();
                if (!$wallet) {
                    return;
                }

                $meta = $tx->meta ?? [];
                $meta['flutterwave_id'] = $flutterwaveId !== '' ? $flutterwaveId : ($meta['flutterwave_id'] ?? null);
                $meta['flw_ref'] = (string) ($data['flw_ref'] ?? ($meta['flw_ref'] ?? ''));
                $meta['gateway_response'] = (string) ($data['processor_response'] ?? ($meta['gateway_response'] ?? 'success'));
                $meta['paid_at'] = (string) ($data['created_at'] ?? ($meta['paid_at'] ?? now()->toISOString()));
                $meta['channel'] = (string) ($data['payment_type'] ?? ($meta['channel'] ?? 'flutterwave'));
                $meta['flutterwave_payload'] = $data;
                $meta['webhook_event'] = 'charge.completed';
                $feeMeta = $this->resolveFundingCredit((int) $tx->amount, $meta);
                $meta['fee_kobo'] = $feeMeta['fee_kobo'];
                $meta['fee_naira'] = $feeMeta['fee_naira'];
                $meta['credited_kobo'] = $feeMeta['credited_kobo'];

                $tx->status = 'success';
                $tx->channel = $tx->channel ?: 'flutterwave';
                $tx->meta = $meta;
                $tx->save();

                $wallet->balance += (int) $feeMeta['credited_kobo'];
                $wallet->save();

                $user = $wallet->user()->lockForUpdate()->first();
                if ($user) {
                    $this->markReferralQualified($user);
                    $this->notifyWalletFunding(
                        $user,
                        (int) $feeMeta['credited_kobo'],
                        (string) $tx->reference,
                        (string) ($tx->channel ?? 'flutterwave'),
                        (int) ($wallet->balance - (int) $feeMeta['credited_kobo']),
                        (int) $wallet->balance,
                    );
                }
            });

            return;
        }

        if ($customerEmail === '') {
            return;
        }

        $user = User::query()->where('email', $customerEmail)->first();
        if (!$user || !$user->wallet) {
            return;
        }

        DB::transaction(function () use ($data, $user, $reference, $amountKobo, $flutterwaveId) {
            $wallet = $user->wallet()->lockForUpdate()->first();
            if (!$wallet) {
                return;
            }

            $referenceToUse = $this->ensureUniqueReference($reference);
            $feeMeta = $this->resolveFundingCredit($amountKobo, []);

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amountKobo,
                'reference' => $referenceToUse,
                'status' => 'success',
                'channel' => 'flutterwave',
                'description' => 'Wallet funding via Flutterwave webhook',
                'meta' => [
                    'flutterwave_id' => $flutterwaveId !== '' ? $flutterwaveId : null,
                    'flw_ref' => (string) ($data['flw_ref'] ?? ''),
                    'amount_naira' => $amountKobo / 100,
                    'fee_kobo' => $feeMeta['fee_kobo'],
                    'fee_naira' => $feeMeta['fee_naira'],
                    'gateway_response' => (string) ($data['processor_response'] ?? 'success'),
                    'paid_at' => (string) ($data['created_at'] ?? now()->toISOString()),
                    'channel' => (string) ($data['payment_type'] ?? 'flutterwave'),
                    'flutterwave_payload' => $data,
                    'webhook_event' => 'charge.completed',
                    'credited_kobo' => $feeMeta['credited_kobo'],
                ],
            ]);

            $wallet->balance += (int) $feeMeta['credited_kobo'];
            $wallet->save();

            $this->markReferralQualified($user);
            $this->notifyWalletFunding(
                $user,
                (int) $feeMeta['credited_kobo'],
                $referenceToUse,
                'flutterwave',
                (int) ($wallet->balance - (int) $feeMeta['credited_kobo']),
                (int) $wallet->balance,
            );
        });
    }

    private function handleVirtualAccountTransfer(array $data, array $meta): void
    {
        $amountKobo = (int) round(((float) ($data['amount'] ?? $data['charged_amount'] ?? 0)) * 100);
        $txRef = trim((string) ($data['tx_ref'] ?? ''));
        $flutterwaveId = trim((string) ($data['flw_ref'] ?? ($data['id'] ?? '')));
        $customerEmail = trim((string) data_get($data, 'customer.email', ''));
        $virtualAccountNumber = trim((string) (
            $data['account_number']
            ?? data_get($data, 'meta.authorization.transfer_account')
            ?? data_get($data, 'authorization.transfer_account')
            ?? data_get($meta, 'beneficiaryaccountnumber')
            ?? data_get($meta, 'accountnumber')
            ?? ''
        ));

        if ($amountKobo <= 0) {
            Log::warning('Flutterwave virtual account transfer ignored due to invalid amount.', [
                'data' => $data,
                'meta' => $meta,
            ]);
            return;
        }

        $user = null;
        if ($txRef !== '') {
            $user = User::query()->where('virtual_account_metadata->tx_ref', $txRef)->first();
        }
        if (!$user && $virtualAccountNumber !== '') {
            $user = User::query()->where('virtual_account_number', $virtualAccountNumber)->first();
        }
        if (!$user && $customerEmail !== '') {
            $user = User::query()->where('email', $customerEmail)->first();
        }
        if (!$user || !$user->wallet) {
            Log::warning('Flutterwave virtual account transfer user not found.', [
                'tx_ref' => $txRef,
                'virtual_account_number' => $virtualAccountNumber,
                'customer_email' => $customerEmail,
                'flutterwave_id' => $flutterwaveId,
                'data' => $data,
                'meta' => $meta,
            ]);
            return;
        }

        DB::transaction(function () use ($data, $meta, $user, $amountKobo, $flutterwaveId, $txRef) {
            $wallet = $user->wallet()->lockForUpdate()->first();
            if (!$wallet) {
                return;
            }

            $existing = null;
            if ($flutterwaveId !== '') {
                $existing = WalletTransaction::query()
                    ->where('meta->flutterwave_id', $flutterwaveId)
                    ->lockForUpdate()
                    ->first();
            }
            if (!$existing && $txRef !== '') {
                $existing = WalletTransaction::query()
                    ->where('meta->flutterwave_payload->tx_ref', $txRef)
                    ->lockForUpdate()
                    ->first();
            }
            if ($existing && $existing->status === 'success') {
                Log::info('Flutterwave virtual account transfer ignored because it was already credited.', [
                    'tx_ref' => $txRef,
                    'flutterwave_id' => $flutterwaveId,
                    'existing_reference' => $existing->reference,
                ]);
                return;
            }

            $baseReference = 'FLW_VA_'.($flutterwaveId !== '' ? Str::upper(Str::slug($flutterwaveId, '')) : Str::upper(Str::random(10)));
            $referenceToUse = $this->ensureUniqueReference($baseReference);
            $feeMeta = $this->resolveFundingCredit($amountKobo, []);

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amountKobo,
                'reference' => $referenceToUse,
                'status' => 'success',
                'channel' => 'flutterwave_virtual_account',
                'description' => 'Wallet funding via Flutterwave virtual account transfer',
                'meta' => [
                    'flutterwave_id' => $flutterwaveId !== '' ? $flutterwaveId : null,
                    'amount_naira' => $amountKobo / 100,
                    'fee_kobo' => $feeMeta['fee_kobo'],
                    'fee_naira' => $feeMeta['fee_naira'],
                    'channel' => (string) ($data['payment_type'] ?? 'bank_transfer'),
                    'paid_at' => (string) ($data['created_at'] ?? now()->toISOString()),
                    'flutterwave_payload' => $data,
                    'flutterwave_meta' => $meta,
                    'webhook_event' => 'BANK_TRANSFER_TRANSACTION',
                    'credited_kobo' => $feeMeta['credited_kobo'],
                ],
            ]);

            $wallet->balance += (int) $feeMeta['credited_kobo'];
            $wallet->save();

            $this->markReferralQualified($user);
            $this->notifyWalletFunding(
                $user,
                (int) $feeMeta['credited_kobo'],
                $referenceToUse,
                'flutterwave_virtual_account',
                (int) ($wallet->balance - (int) $feeMeta['credited_kobo']),
                (int) $wallet->balance,
            );
        });
    }

    private function ensureUniqueReference(string $reference): string
    {
        $ref = trim($reference) !== '' ? trim($reference) : 'FLW_TX_'.Str::upper(Str::random(12));
        if (!WalletTransaction::query()->where('reference', $ref)->exists()) {
            return $ref;
        }

        do {
            $candidate = $ref.'_'.Str::upper(Str::random(4));
        } while (WalletTransaction::query()->where('reference', $candidate)->exists());

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{fee_kobo:int,fee_naira:float,credited_kobo:int}
     */
    private function resolveFundingCredit(int $grossKobo, array $meta): array
    {
        $feeKobo = array_key_exists('fee_kobo', $meta)
            ? max(0, (int) ($meta['fee_kobo'] ?? 0))
            : $this->fundingFeeKobo();

        $creditedKobo = array_key_exists('credited_kobo', $meta)
            ? max(0, (int) ($meta['credited_kobo'] ?? 0))
            : max(0, $grossKobo - $feeKobo);

        if (!array_key_exists('credited_kobo', $meta) && $creditedKobo === 0 && $grossKobo > 0 && $feeKobo === 0) {
            $creditedKobo = $grossKobo;
        }

        return [
            'fee_kobo' => $feeKobo,
            'fee_naira' => $feeKobo / 100,
            'credited_kobo' => $creditedKobo,
        ];
    }

    private function fundingFeeKobo(): int
    {
        $feeNaira = (float) setting('wallet_funding_fee', 50);

        return max(0, (int) round($feeNaira * 100));
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

    private function markReferralQualified(User $user): void
    {
        if ((int) ($user->referred_by_user_id ?? 0) <= 0) {
            return;
        }

        if ($user->referral_qualified_at !== null) {
            return;
        }

        $user->referral_qualified_at = now();
        $user->save();
    }

    private function notifyWalletFunding(
        User $user,
        int $creditedKobo,
        string $reference,
        string $channel,
        int $balanceBeforeKobo,
        int $balanceAfterKobo
    ): void {
        if ($creditedKobo <= 0) {
            return;
        }

        try {
            $user->notify(new UserWalletActivityNotification(
                'Wallet Funded',
                'N' . number_format($creditedKobo / 100, 2) . ' has been added to your wallet.',
                [
                    'type' => 'credit',
                    'channel' => $channel,
                    'reference' => $reference,
                    'amount_kobo' => $creditedKobo,
                    'balance_before_kobo' => $balanceBeforeKobo,
                    'balance_after_kobo' => $balanceAfterKobo,
                ]
            ));
        } catch (\Throwable $e) {
            Log::warning('Wallet funding notification failed.', [
                'user_id' => $user->id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
