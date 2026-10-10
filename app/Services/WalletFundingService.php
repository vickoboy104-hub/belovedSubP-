<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every naira that arrives in a customer's wallet is banked here, whether it
 * came from Flutterwave checkout, a bank transfer into a virtual account, or the
 * webhook telling us about it afterwards. One place for the funding fee, the
 * ledger row and the customer's email means no arrival path can quietly skip a
 * step - and one place to make the write idempotent means the same deposit
 * being reported twice can never pay anybody twice.
 */
class WalletFundingService
{
    /**
     * Bank one Flutterwave charge that somebody already verified as successful.
     *
     * @param  array<string, mixed>  $charge
     * @param  array<string, mixed>  $extraMeta
     * @return int kobo actually added to the balance, zero when nothing moved
     */
    public function creditFlutterwaveCharge(
        Wallet $wallet,
        array $charge,
        string $channel,
        string $via,
        array $extraMeta = [],
    ): int {
        $grossKobo = (int) round(((float) ($charge['charged_amount'] ?? $charge['amount'] ?? 0)) * 100);
        if ($grossKobo <= 0) {
            return 0;
        }

        $txRef = trim((string) ($charge['tx_ref'] ?? ''));
        $chargeId = trim((string) ($charge['id'] ?? ''));
        $flutterwaveId = trim((string) ($charge['flw_ref'] ?? ''));
        $gatewayReference = trim((string) ($charge['reference'] ?? ''));

        return (int) DB::transaction(function () use (
            $wallet,
            $charge,
            $channel,
            $via,
            $extraMeta,
            $grossKobo,
            $txRef,
            $chargeId,
            $flutterwaveId,
            $gatewayReference
        ): int {
            $lockedWallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            if (!$lockedWallet) {
                return 0;
            }

            $row = $this->arrivalRow($lockedWallet, $txRef, $chargeId, $flutterwaveId, $gatewayReference);

            if ($row && $row->status === 'success') {
                Log::info('Flutterwave deposit ignored because this wallet was already credited for it.', [
                    'wallet_id' => $lockedWallet->id,
                    'reference' => $row->reference,
                    'flutterwave_charge_id' => $chargeId,
                    'arrived_via' => $via,
                ]);

                return 0;
            }

            if ($row && $grossKobo < (int) $row->amount) {
                Log::warning('Flutterwave reported less than the amount this deposit asked for.', [
                    'wallet_id' => $lockedWallet->id,
                    'reference' => $row->reference,
                    'expected_kobo' => (int) $row->amount,
                    'received_kobo' => $grossKobo,
                ]);

                return 0;
            }

            $meta = (array) ($row->meta ?? []);

            // The fee the customer agreed to only stands for the amount they
            // agreed to. A transfer that arrives short or large is charged the
            // funding fee on what actually landed, and the row is corrected to
            // say so rather than reporting the requested figure.
            $asRequested = $row && (int) $row->amount === $grossKobo;
            $credit = $this->resolveCredit($grossKobo, $asRequested ? $meta : []);
            $beforeKobo = (int) $lockedWallet->balance;
            $afterKobo = $beforeKobo + $credit['credited_kobo'];
            $reference = $row?->reference ?: $this->uniqueReference($this->arrivalReference($chargeId, $flutterwaveId));

            $arrivalMeta = array_merge($meta, $extraMeta, [
                'amount_naira' => $grossKobo / 100,
                'fee_kobo' => $credit['fee_kobo'],
                'fee_naira' => $credit['fee_naira'],
                'credited_kobo' => $credit['credited_kobo'],
                'flutterwave_charge_id' => $chargeId !== '' ? $chargeId : ($meta['flutterwave_charge_id'] ?? null),
                'flutterwave_id' => $flutterwaveId !== '' ? $flutterwaveId : ($meta['flutterwave_id'] ?? null),
                'flw_ref' => $flutterwaveId !== '' ? $flutterwaveId : ($meta['flw_ref'] ?? null),
                'flutterwave_reference' => $gatewayReference !== '' ? $gatewayReference : ($meta['flutterwave_reference'] ?? null),
                'tx_ref' => $txRef !== '' ? $txRef : ($meta['tx_ref'] ?? null),
                'virtual_account_number' => $this->chargeAccountNumber($charge) ?: ($meta['virtual_account_number'] ?? null),
                'currency' => strtoupper((string) ($charge['currency'] ?? ($meta['currency'] ?? 'NGN'))),
                'channel' => $this->paymentMethod($charge, $channel),
                'paid_at' => (string) ($charge['created_at'] ?? ($meta['paid_at'] ?? now()->toIso8601String())),
                'gateway_response' => $this->textual($charge['processor_response'] ?? ($meta['gateway_response'] ?? 'success')),
                'flutterwave_payload' => $charge,
                'arrived_via' => $via,
                'balance_before_kobo' => $beforeKobo,
                'balance_after_kobo' => $afterKobo,
            ]);

            if ($row) {
                $row->fill([
                    'amount' => $grossKobo,
                    'status' => 'success',
                    'channel' => (string) ($row->channel ?: $channel),
                    'meta' => $arrivalMeta,
                ])->save();
            } else {
                WalletTransaction::create([
                    'wallet_id' => $lockedWallet->id,
                    'type' => 'credit',
                    'amount' => $grossKobo,
                    'reference' => $reference,
                    'status' => 'success',
                    'channel' => $channel,
                    'description' => 'Wallet funding via '.$channel,
                    'meta' => $arrivalMeta,
                ]);
            }

            $lockedWallet->balance = $afterKobo;
            $lockedWallet->save();

            $user = $lockedWallet->user()->lockForUpdate()->first();
            if ($user) {
                $this->markReferralQualified($user);
                $this->notifyArrival($user, $credit['credited_kobo'], $reference, $channel, $beforeKobo, $afterKobo);
            }

            return (int) $credit['credited_kobo'];
        });
    }

    /**
     * Tell the customer what their wallet just did. The dashboard record and the
     * email are the same words, so nobody has to open the site to learn whether
     * money they sent was received.
     */
    public function notifyArrival(
        User $user,
        int $creditedKobo,
        string $reference,
        string $channel,
        int $balanceBeforeKobo,
        int $balanceAfterKobo,
    ): void {
        if ($creditedKobo <= 0) {
            return;
        }

        $amountNaira = 'N'.number_format($creditedKobo / 100, 2);
        $balanceNaira = 'N'.number_format($balanceAfterKobo / 100, 2);

        try {
            $user->notify(new UserWalletActivityNotification(
                'Payment received',
                $amountNaira.' has been added to your wallet from '.$this->arrivalLabel($channel).'. '
                    .'Your balance is now '.$balanceNaira.'.',
                [
                    'type' => 'credit',
                    'channel' => $channel,
                    'reference' => $reference,
                    'amount_kobo' => $creditedKobo,
                    'balance_before_kobo' => $balanceBeforeKobo,
                    'balance_after_kobo' => $balanceAfterKobo,
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('Wallet funding notification failed.', [
                'user_id' => $user->id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How the money arrived, said the way a customer would say it.
     */
    private function arrivalLabel(string $channel): string
    {
        return match ($channel) {
            'flutterwave_virtual_account', 'bank_transfer' => 'your bank transfer',
            'card' => 'your card payment',
            'flutterwave' => 'the Flutterwave payment page',
            'admin_manual_credit' => 'our support team',
            default => str_replace('_', ' ', $channel),
        };
    }

    public function fundingFeeKobo(): int
    {
        return max(0, (int) round(((float) setting('wallet_funding_fee', 50)) * 100));
    }

    /**
     * The row this deposit already has: the funding request the customer started
     * themselves, or an earlier report of the very same charge that never got
     * banked. Only credits are ever considered - a purchase debit that happens to
     * carry one of these references must not be mistaken for money arriving.
     */
    private function arrivalRow(
        Wallet $wallet,
        string $txRef,
        string $chargeId,
        string $flutterwaveId,
        string $gatewayReference,
    ): ?WalletTransaction {
        $rows = $wallet->transactions()
            ->where('type', 'credit')
            ->where(function ($query) use ($txRef, $chargeId, $flutterwaveId, $gatewayReference) {
                if ($txRef !== '') {
                    $query->orWhere('reference', $txRef)->orWhere('meta->tx_ref', $txRef);
                }
                if ($chargeId !== '') {
                    $query->orWhere('meta->flutterwave_charge_id', $chargeId)
                        ->orWhere('meta->flutterwave_payload.id', $chargeId);
                }
                if ($flutterwaveId !== '') {
                    $query->orWhere('meta->flutterwave_id', $flutterwaveId)
                        ->orWhere('meta->flw_ref', $flutterwaveId);
                }
                if ($gatewayReference !== '') {
                    $query->orWhere('meta->flutterwave_reference', $gatewayReference)
                        ->orWhere('meta->flutterwave_payload.reference', $gatewayReference);
                }
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        // A permanent account is issued once and paid into as many times as its
        // owner likes, and every one of those transfers carries the single
        // reference the account was created under. So that reference can only
        // ever point at a funding request still owed - never at a deposit already
        // banked, or the second payment of a customer's life is thrown away as a
        // replay of the first. Only the charge's own identity says "paid twice".
        $alreadyBanked = $rows->first(
            fn (WalletTransaction $row): bool => $row->status === 'success'
                && $this->isSameCharge($row, $chargeId, $flutterwaveId, $gatewayReference)
        );

        if ($alreadyBanked) {
            return $alreadyBanked;
        }

        // Only a request still open for business is taken over by a new charge.
        // One that was already closed out as abandoned carries an amount nobody
        // promised any more, and letting it stand would refuse a smaller transfer
        // into the same account number.
        return $rows->first(
            fn (WalletTransaction $row): bool => $row->status === 'pending'
                && $txRef !== ''
                && ((string) $row->reference === $txRef || (string) ($row->meta['tx_ref'] ?? '') === $txRef)
        );
    }

    /**
     * Whether this ledger row and this charge are the same money, judged only on
     * the identifiers Flutterwave gives an individual charge.
     */
    private function isSameCharge(
        WalletTransaction $row,
        string $chargeId,
        string $flutterwaveId,
        string $gatewayReference,
    ): bool {
        if ($chargeId === '' && $flutterwaveId === '' && $gatewayReference === '') {
            return false;
        }

        $meta = (array) ($row->meta ?? []);
        $payload = (array) ($meta['flutterwave_payload'] ?? []);

        $recorded = array_values(array_filter([
            (string) ($meta['flutterwave_charge_id'] ?? ''),
            (string) ($payload['id'] ?? ''),
            (string) ($meta['flw_ref'] ?? ''),
            (string) ($meta['flutterwave_id'] ?? ''),
            (string) ($payload['flw_ref'] ?? ''),
            (string) ($meta['flutterwave_reference'] ?? ''),
            (string) ($payload['reference'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));

        foreach ([$chargeId, $flutterwaveId, $gatewayReference] as $token) {
            if ($token !== '' && in_array($token, $recorded, true)) {
                return true;
            }
        }

        // A row written here without a funding request behind it names itself
        // after the charge that funded it, sometimes with a suffix to keep the
        // reference unique.
        $derived = $this->arrivalReference($chargeId, $flutterwaveId);

        return $derived !== 'FLW_VA_' && str_starts_with((string) $row->reference, $derived);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{fee_kobo:int,fee_naira:float,credited_kobo:int}
     */
    private function resolveCredit(int $grossKobo, array $meta): array
    {
        $feeKobo = array_key_exists('fee_kobo', $meta)
            ? max(0, (int) ($meta['fee_kobo'] ?? 0))
            : $this->fundingFeeKobo();

        $creditedKobo = array_key_exists('credited_kobo', $meta)
            ? max(0, (int) ($meta['credited_kobo'] ?? 0))
            : max(0, $grossKobo - $feeKobo);

        if ($creditedKobo === 0 && $grossKobo > 0 && $feeKobo === 0) {
            $creditedKobo = $grossKobo;
        }

        return [
            'fee_kobo' => $feeKobo,
            'fee_naira' => $feeKobo / 100,
            'credited_kobo' => $creditedKobo,
        ];
    }

    private function arrivalReference(string $chargeId, string $flutterwaveId): string
    {
        $token = $chargeId !== '' ? $chargeId : ($flutterwaveId !== '' ? $flutterwaveId : Str::upper(Str::random(10)));

        return 'FLW_VA_'.Str::upper(Str::slug((string) $token, ''));
    }

    private function uniqueReference(string $reference): string
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
     * The bank account the money was sent to, wherever Flutterwave chose to
     * report it. Deposit checks use this to prove a transfer is ours.
     *
     * @param  array<string, mixed>  $charge
     */
    public function chargeAccountNumber(array $charge): string
    {
        return trim((string) (
            $charge['account_number']
            ?? data_get($charge, 'meta.authorization.transfer_account')
            ?? data_get($charge, 'authorization.transfer_account')
            ?? ''
        ));
    }

    /** @param  array<string, mixed>  $charge */
    private function paymentMethod(array $charge, string $fallback): string
    {
        $method = trim((string) ($charge['payment_type'] ?? data_get($charge, 'payment_method.type', '')));

        return $method !== '' ? $method : $fallback;
    }

    private function textual(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : 'success';
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
}
