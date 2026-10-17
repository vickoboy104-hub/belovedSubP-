<?php

namespace App\Services;

use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Database\QueryException;
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
    public function __construct(
        private readonly WalletLedger $ledger,
    ) {
    }

    /**
     * Bank one Flutterwave charge that somebody already verified as successful.
     *
     * $ownershipApproved is for the one case where a human has already read this
     * transfer and named the customer it belongs to: the admin resolution screen.
     * It never skips the global charge claim, so an approved credit can still not
     * be paid twice, and no arrival path a customer or gateway controls can set it.
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
        bool $ownershipApproved = false,
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
            $gatewayReference,
            $ownershipApproved
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

            /* An account number names an account, not a person, and every path
               that brought this charge here resolved its customer with a
               first-match lookup. So before anything is banked: when this
               transfer can be proved to belong to more than one customer, or to a
               customer other than this wallet's owner, pay nobody and hold it for
               a human to decide. */
            if (!$ownershipApproved) {
                $dealtWith = $this->chargeDealtWithBefore($lockedWallet, $chargeId, $flutterwaveId, $txRef);

                if ($dealtWith !== null) {
                    $this->holdUnattributedCharge(
                        $lockedWallet,
                        $charge,
                        $channel,
                        $via,
                        [
                            'ambiguous' => true,
                            'reason' => $dealtWith['reason'],
                            'holders' => [(int) $lockedWallet->user_id],
                        ],
                        $grossKobo,
                        $chargeId,
                        $flutterwaveId,
                        $txRef
                    );

                    return 0;
                }

                $attribution = $this->depositAttribution($lockedWallet, $charge, $txRef);

                if ($attribution['ambiguous']) {
                    $this->holdUnattributedCharge(
                        $lockedWallet,
                        $charge,
                        $channel,
                        $via,
                        $attribution,
                        $grossKobo,
                        $chargeId,
                        $flutterwaveId,
                        $txRef
                    );

                    return 0;
                }
            }

            /* The checks above can only ever see this wallet, while the wallet a
               charge is routed to is chosen by lookups that are not unique. This
               claim is the one that is global: take the charge in the database
               before any balance moves, or somebody else already has. */
            if (!$this->claimCharge($lockedWallet, $chargeId, $flutterwaveId, $grossKobo, $via, $txRef)) {
                return 0;
            }

            $meta = (array) ($row->meta ?? []);

            // The fee the customer agreed to only stands for the amount they
            // agreed to. A transfer that arrives short or large is charged the
            // funding fee on what actually landed, and the row is corrected to
            // say so rather than reporting the requested figure.
            $asRequested = $row && (int) $row->amount === $grossKobo;
            $credit = $this->resolveCredit($grossKobo, $asRequested ? $meta : []);
            /* The arrival is added to whatever the wallet actually holds, not to the
               figure this request read, so a webhook and the deposit sweep banking the
               same wallet at the same moment both show up instead of one replacing the
               other. */
            [$beforeKobo, $afterKobo] = $this->ledger->shift($lockedWallet, 'credit', (int) $credit['credited_kobo']);
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

            $user = $lockedWallet->user()->lockForUpdate()->first();
            if ($user) {
                $this->markReferralQualified($user);
                $this->notifyArrival($user, $credit['credited_kobo'], $reference, $channel, $beforeKobo, $afterKobo);
            }

            return (int) $credit['credited_kobo'];
        });
    }

    /**
     * Finish what a machine could not decide: an admin has read this transfer and
     * named the customer it belongs to. The global charge claim still applies, so
     * a held deposit that has already been paid out - by a later webhook, or by a
     * second admin - cannot be paid a second time from the same record.
     */
    public function creditHeldDepositTo(FlutterwaveHeldDeposit $held, User $customer, User $admin, string $note): int
    {
        return (int) DB::transaction(function () use ($held, $customer, $admin, $note): int {
            $wallet = Wallet::query()->firstOrCreate(['user_id' => $customer->id], ['balance' => 0]);

            $credited = $this->creditFlutterwaveCharge(
                $wallet,
                (array) ($held->payload ?? []),
                (string) ($held->channel ?: 'flutterwave_virtual_account'),
                'admin_resolution',
                [
                    'resolved_held_deposit_id' => $held->id,
                    'resolved_by_user_id' => $admin->id,
                    'resolution_note' => $note,
                    'held_because' => $held->reason,
                    'held_candidate_user_ids' => $held->holder_user_ids,
                ],
                true
            );

            if ($credited <= 0) {
                return 0;
            }

            FlutterwaveHeldDeposit::query()
                ->whereKey($held->id)
                ->where('status', 'held')
                ->update([
                    'status' => 'credited',
                    'resolution_note' => $note,
                    'resolved_by_user_id' => $admin->id,
                    'resolved_wallet_id' => $wallet->id,
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

            return $credited;
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
     * Claim this charge in the database before a balance moves. The claim is one
     * row per charge identity with a unique index behind it, so it holds across
     * every wallet, every arrival path and whatever else has already been paid for
     * this same money. A payload Flutterwave described without any charge identity
     * at all is not refused - there is simply nothing global to claim.
     */
    private function claimCharge(
        Wallet $wallet,
        string $chargeId,
        string $flutterwaveId,
        int $grossKobo,
        string $via,
        string $txRef,
    ): bool {
        $tokens = [];
        foreach ([$chargeId, $flutterwaveId] as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        if ($tokens === []) {
            return true;
        }

        $tokenList = array_keys($tokens);

        if (DB::table('flutterwave_charge_claims')->whereIn('charge_token', $tokenList)->exists()) {
            $this->logRefusedAsAlreadyClaimed($wallet, $tokenList, $via);

            return false;
        }

        $now = now();
        $claims = [];
        foreach ($tokenList as $token) {
            $claims[] = [
                'charge_token' => $token,
                'wallet_id' => $wallet->id,
                'reference' => $txRef !== '' ? $txRef : null,
                'amount_kobo' => $grossKobo,
                'arrived_via' => $via,
                'created_at' => $now,
            ];
        }

        try {
            // Every identity in one statement: a charge that loses the race must
            // leave no half-claim behind, or the deposit gets locked out of the
            // wallet that genuinely holds it.
            DB::table('flutterwave_charge_claims')->insert($claims);
        } catch (QueryException $e) {
            if (!$this->isAlreadyTaken($e)) {
                // Anything that is not the unique index - a missing table, a dead
                // link - must not be filed as "somebody already had this money".
                // Let it fail the request: the deposit stays pending and visible to
                // deposits:audit, instead of vanishing into an info log.
                throw $e;
            }

            $this->logRefusedAsAlreadyClaimed($wallet, $tokenList, $via, $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * The one refusal that is correct: the unique index on charge_token said no,
     * which is MySQL 1062 / sqlite 2067, both reported as SQLSTATE 23000.
     */
    private function isAlreadyTaken(QueryException $e): bool
    {
        if (in_array((int) ($e->errorInfo[1] ?? 0), [1062, 2067], true)) {
            return true;
        }

        return (string) $e->getCode() === '23000'
            && str_contains(strtolower($e->getMessage()), 'unique');
    }

    /** @param list<string> $tokens */
    private function logRefusedAsAlreadyClaimed(Wallet $wallet, array $tokens, string $via, string $reason = ''): void
    {
        Log::info('Flutterwave deposit ignored because this charge has already been paid out.', [
            'wallet_id' => $wallet->id,
            'flutterwave_charge_tokens' => $tokens,
            'arrived_via' => $via,
            'reason' => $reason,
        ]);
    }

    /**
     * Who this verified transfer can be proved to belong to.
     *
     * The strongest evidence is a reference this wallet started itself: this site
     * minted it, and the customer who was told to send money is the one it was
     * given to. Weaker than that there is only the bank account the transfer
     * arrived in, and an account number is not a person - Flutterwave reissues
     * numbers, admins type them onto accounts, and closed funding requests keep
     * naming the number they were issued. So an account-only match is accepted
     * exactly once: when one customer holds that number and it is this wallet's
     * owner. Anything else is a guess, and a guess moves somebody's money.
     *
     * @param  array<string, mixed>  $charge
     * @return array{ambiguous:bool,reason:string,holders:list<int>}
     */
    private function depositAttribution(Wallet $wallet, array $charge, string $txRef): array
    {
        $account = $this->chargeAccountNumber($charge);
        $holders = $account !== '' ? $this->accountHolders($account) : [];

        if ($txRef !== '' && $this->ownsReference($wallet, $txRef)) {
            return ['ambiguous' => false, 'reason' => '', 'holders' => $holders];
        }

        if ($holders === []) {
            // Nothing here contradicts the lookup that routed this charge, and a
            // transfer Flutterwave described without an account number cannot be
            // attributed by one either. Left to the normal path.
            return ['ambiguous' => false, 'reason' => '', 'holders' => []];
        }

        if (count($holders) > 1) {
            return ['ambiguous' => true, 'reason' => 'shared_account_number', 'holders' => $holders];
        }

        if ($holders[0] !== (int) $wallet->user_id) {
            return ['ambiguous' => true, 'reason' => 'wrong_wallet', 'holders' => $holders];
        }

        return ['ambiguous' => false, 'reason' => '', 'holders' => $holders];
    }

    /**
     * Every customer this site currently points at one account number, across the
     * three places an account number is recorded.
     *
     * @return list<int>
     */
    private function accountHolders(string $account): array
    {
        $ids = DB::table('users')
            ->where('virtual_account_number', $account)
            ->pluck('id')
            ->all();

        $ids = array_merge($ids, DB::table('users')
            ->where('virtual_account_metadata->temporary_virtual_account->account_number', $account)
            ->pluck('id')
            ->all());

        // A number a customer was given and then replaced is still theirs for a
        // transfer already on its way, so the funding requests that name it count.
        $ids = array_merge($ids, DB::table('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallet_transactions.type', 'credit')
            ->where('wallet_transactions.meta->virtual_account_number', $account)
            ->pluck('wallets.user_id')
            ->all());

        $ids = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $ids
        )));

        sort($ids);

        return $ids;
    }

    private function ownsReference(Wallet $wallet, string $txRef): bool
    {
        $startedHere = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where(function ($query) use ($txRef): void {
                $query->where('reference', $txRef)->orWhere('meta->tx_ref', $txRef);
            })
            ->exists();

        if ($startedHere) {
            return true;
        }

        return User::query()
            ->whereKey($wallet->user_id)
            ->where(function ($query) use ($txRef): void {
                $query->where('virtual_account_metadata->tx_ref', $txRef)
                    ->orWhere('virtual_account_metadata->temporary_virtual_account->tx_ref', $txRef);
            })
            ->exists();
    }

    /**
     * Write the transfer down so a person can finish the job, and pay nobody.
     * One row per charge: a webhook and the deposit sweep asking about the same
     * money must produce one held deposit, not a queue of near-identical ones.
     *
     * @param  array<string, mixed>  $charge
     * @param  array{ambiguous:bool,reason:string,holders:list<int>}  $attribution
     */
    private function holdUnattributedCharge(
        Wallet $wallet,
        array $charge,
        string $channel,
        string $via,
        array $attribution,
        int $grossKobo,
        string $chargeId,
        string $flutterwaveId,
        string $txRef
    ): void {
        $token = $this->heldDepositToken($charge, $chargeId, $flutterwaveId, $txRef, $grossKobo);
        $account = $this->chargeAccountNumber($charge);
        $now = now();

        $written = DB::table('flutterwave_held_deposits')->insertOrIgnore([
            'charge_token' => $token,
            'flutterwave_charge_id' => $chargeId !== '' ? $chargeId : null,
            'flutterwave_id' => $flutterwaveId !== '' ? $flutterwaveId : null,
            'tx_ref' => $txRef !== '' ? $txRef : null,
            'virtual_account_number' => $account !== '' ? $account : null,
            'amount_kobo' => $grossKobo,
            'currency' => strtoupper((string) ($charge['currency'] ?? 'NGN')),
            'channel' => $channel,
            'arrived_via' => $via,
            'reason' => $attribution['reason'],
            'holder_user_ids' => json_encode($attribution['holders']),
            'payload' => json_encode($charge),
            'status' => 'held',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::warning('Flutterwave deposit held for reconciliation because no single customer owns it.', [
            'wallet_id_considered' => $wallet->id,
            'flutterwave_charge_id' => $chargeId,
            'flutterwave_id' => $flutterwaveId,
            'tx_ref' => $txRef,
            'virtual_account_number' => $account,
            'amount_kobo' => $grossKobo,
            'reason' => $attribution['reason'],
            'candidate_user_ids' => $attribution['holders'],
            'arrived_via' => $via,
            'newly_recorded' => $written === 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function heldDepositToken(array $charge, string $chargeId, string $flutterwaveId, string $txRef, int $grossKobo): string
    {
        if ($chargeId !== '') {
            return $chargeId;
        }

        if ($flutterwaveId !== '') {
            return $flutterwaveId;
        }

        // A payload with no charge identity still needs a row of its own, or the
        // held deposit would be recorded once and then silently dropped on every
        // later report of the same money.
        return 'NOID:'.sha1(implode('|', [
            $this->chargeAccountNumber($charge),
            $txRef,
            $grossKobo,
            (string) ($charge['created_at'] ?? ''),
        ]));
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
     * Whether this wallet has already dealt with this exact money.
     *
     * Two kinds of record answer yes, and they are looked up differently on purpose.
     *
     * A request an admin marked as settled by hand is matched on the funding
     * reference: that is the only handle a manual settlement has, because the money
     * was moved outside the gateway and left no charge identity behind anywhere. The
     * cost is honest and bounded - a permanent account repeats one reference, so a
     * marked request also holds later transfers into that account until an admin
     * releases or reopens it. Held is the safe side of that trade: the money is
     * written down, paid to nobody, and one click finishes it.
     *
     * A charge that this wallet already saw close as failed is matched only on the
     * identifiers Flutterwave gives an individual charge. Matching it on the account
     * number or the funding reference instead would hold every genuine new transfer
     * into an already-used account behind one old failure.
     *
     * @return array{reason:string,transaction_id:int}|null
     */
    private function chargeDealtWithBefore(Wallet $wallet, string $chargeId, string $flutterwaveId, string $txRef): ?array
    {
        $marked = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where(function ($query) use ($txRef): void {
                $query->where('meta->manually_settled', true);

                if ($txRef !== '') {
                    $query->where(function ($inner) use ($txRef): void {
                        $inner->where('reference', $txRef)->orWhere('meta->tx_ref', $txRef);
                    });
                }
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->first();

        if ($marked) {
            return ['reason' => 'already_settled_manually', 'transaction_id' => (int) $marked->id];
        }

        if ($chargeId === '' && $flutterwaveId === '') {
            return null;
        }

        $closed = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('status', 'failed')
            ->where(function ($query) use ($chargeId, $flutterwaveId): void {
                if ($chargeId !== '') {
                    $query->orWhere('meta->flutterwave_charge_id', $chargeId)
                        ->orWhere('meta->flutterwave_payload.id', $chargeId);
                }
                if ($flutterwaveId !== '') {
                    $query->orWhere('meta->flutterwave_id', $flutterwaveId)
                        ->orWhere('meta->flw_ref', $flutterwaveId)
                        ->orWhere('meta->flutterwave_payload.flw_ref', $flutterwaveId);
                }
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->first();

        if ($closed) {
            return ['reason' => 'charge_previously_reported_failed', 'transaction_id' => (int) $closed->id];
        }

        return null;
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
