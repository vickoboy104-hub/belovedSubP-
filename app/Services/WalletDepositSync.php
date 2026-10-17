<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A bank transfer into a virtual account is only ever reported to this site by
 * Flutterwave's webhook, and a webhook only arrives if Flutterwave's servers can
 * reach the site from the outside. On a development machine, behind a VPN, or
 * with a webhook URL that was pasted wrong, the customer's money lands and
 * nothing moves their balance. This asks Flutterwave directly instead, so a
 * deposit is found from the side that has the money.
 */
class WalletDepositSync
{
    /** How long one customer has to wait between checks, in seconds. */
    private const ON_DEMAND_WINDOW = 30;

    private const AUTOMATIC_WINDOW = 180;

    /**
     * References one check is allowed to ask about. Kept above the pending-request
     * limit so an account number that was replaced still gets its turn.
     */
    private const MAX_CANDIDATES = 12;

    /** Each reference costs up to two API calls, so a check is kept small. */
    private const MAX_REFERENCES = 8;

    /**
     * How far back a closed-out funding request is still worth asking about. A
     * transfer that was slower than the request was allowed to wait is still the
     * customer's money, and the account it went to is still theirs.
     */
    private const ABANDONED_LOOKBACK_DAYS = 14;

    /**
     * How long a funding request is allowed to keep saying "still waiting" after
     * Flutterwave was asked about it and reported no transfer. A bank transfer
     * that was really sent is never lost by this: the account number it went to
     * is still asked about on every later check, and money found there writes
     * its own completed credit row.
     */
    private const ABANDONED_AFTER_MINUTES = 60;

    /** Where the walk through dedicated-account holders got to. */
    private const SWEEP_POINTER_CACHE_KEY = 'wallet-deposit-sweep-pointer';

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly WalletFundingService $funding,
    ) {
    }

    /**
     * Ask Flutterwave about money on behalf of customers who are not looking at
     * their own page. A transfer that lands while nobody is watching would
     * otherwise only be found the next time that customer opens their
     * transactions, which is exactly how "I sent money and it did not reflect"
     * happens to a customer who has no reason to log in again.
     *
     * @return array{checked:int,credited:int,credited_kobo:int}
     */
    public function sweep(int $limit = 10): array
    {
        $summary = ['checked' => 0, 'credited' => 0, 'credited_kobo' => 0];

        if (!$this->flutterwave->configured() || $limit <= 0) {
            return $summary;
        }

        $userIds = $this->awaitingWalletUserIds($limit);

        // Whatever the waiting requests did not fill gets spent walking the
        // customers who were handed a dedicated account number, so a transfer
        // that was never announced by a funding request still gets looked for.
        $remaining = $limit - count($userIds);
        if ($remaining > 0) {
            $userIds = array_merge($userIds, $this->accountHolderUserIds($remaining));
        }

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if (!$user) {
                continue;
            }

            $result = $this->sync($user);
            $summary['checked']++;

            if ($result['credited_kobo'] > 0) {
                $summary['credited']++;
                $summary['credited_kobo'] += $result['credited_kobo'];
            }
        }

        return $summary;
    }

    /**
     * Customers with a bank transfer either outstanding or recently written off.
     *
     * @return list<int>
     */
    private function awaitingWalletUserIds(int $limit): array
    {
        return WalletTransaction::query()
            ->from('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallet_transactions.type', 'credit')
            ->where('wallet_transactions.channel', 'like', 'flutterwave%')
            ->where(function ($query): void {
                $query->where('wallet_transactions.status', 'pending')
                    ->orWhere(function ($closed): void {
                        $closed->where('wallet_transactions.status', 'failed')
                            ->where('wallet_transactions.updated_at', '>=', now()->subDays(self::ABANDONED_LOOKBACK_DAYS));
                    });
            })
            ->groupBy('wallets.user_id')
            ->orderByRaw('MIN(wallet_transactions.created_at) ASC')
            ->limit($limit)
            ->pluck('wallets.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The next slice of customers who hold a dedicated account number, walking
     * the list a little further on every run so no account is only ever checked
     * by accident.
     *
     * @return list<int>
     */
    private function accountHolderUserIds(int $limit): array
    {
        $holders = User::query()
            ->whereNotNull('virtual_account_number')
            ->where('virtual_account_number', '!=', '')
            ->orderBy('id');

        $total = (clone $holders)->count();
        if ($total === 0) {
            return [];
        }

        $pointer = (int) Cache::get(self::SWEEP_POINTER_CACHE_KEY, 0);
        if ($pointer >= $total) {
            $pointer = 0;
        }

        $ids = $holders->offset($pointer)->limit($limit)->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        Cache::put(self::SWEEP_POINTER_CACHE_KEY, $pointer + count($ids), now()->addDay());

        return $ids;
    }

    /**
     * @return array{status:string,checked:int,credited_kobo:int}
     */
    public function sync(User $user, bool $onDemand = false): array
    {
        $result = ['status' => 'nothing_to_check', 'checked' => 0, 'credited_kobo' => 0];

        $wallet = $user->wallet;
        if (!$wallet) {
            return ['status' => 'no_wallet'] + $result;
        }

        if (!$this->flutterwave->configured()) {
            return ['status' => 'not_configured'] + $result;
        }

        $candidates = $this->candidates($user, $wallet);
        if ($candidates === []) {
            return $result;
        }

        // The button a customer presses and the quiet check that runs when they
        // open the page are counted separately, so reading the page can never
        // turn their own check into "a check just ran".
        $window = $onDemand ? self::ON_DEMAND_WINDOW : self::AUTOMATIC_WINDOW;
        $throttleKey = 'wallet-deposit-sync-'.($onDemand ? 'button' : 'page').'-'.$user->id;

        if (!Cache::add($throttleKey, true, now()->addSeconds($window))) {
            return ['status' => $this->isAwaiting($wallet) ? 'too_soon' : 'settled'] + $result;
        }

        $creditedKobo = 0;
        $lookupFailed = false;
        $answered = [];

        foreach ($candidates as $candidate) {
            $result['checked']++;

            try {
                $charges = $this->settledCharges($candidate);
                $answered[] = $candidate['ref'];

                foreach ($charges as $charge) {
                    $creditedKobo += $this->funding->creditFlutterwaveCharge(
                        $wallet,
                        $charge,
                        $candidate['channel'],
                        'deposit_check',
                    );
                }
            } catch (\Throwable $e) {
                // A deposit check runs inside the page a customer opens to look at
                // their money. Flutterwave being slow, refusing a reference or
                // returning nonsense is their problem to wait out, not a reason to
                // lose the page.
                $lookupFailed = true;

                Log::warning('Wallet deposit check could not ask Flutterwave about a reference.', [
                    'user_id' => $user->id,
                    'reference' => $candidate['ref'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->closeAbandonedRequests($wallet, $answered);

        $result['credited_kobo'] = $creditedKobo;

        if ($creditedKobo > 0) {
            $result['status'] = 'credited';

            Log::info('Wallet deposit found without a webhook.', [
                'user_id' => $user->id,
                'credited_kobo' => $creditedKobo,
            ]);

            return $result;
        }

        // Nothing new arrived, so say what is actually outstanding rather than
        // promising a transfer that nobody is waiting for any more.
        $result['status'] = match (true) {
            $this->isAwaiting($wallet) => $lookupFailed ? 'lookup_failed' : 'no_deposit_found',
            $lookupFailed => 'lookup_failed',
            default => 'settled',
        };

        return $result;
    }

    /**
     * Funding requests that were started, asked about and never paid. Leaving
     * them pending is what makes a wallet that has already been credited go on
     * advertising "still waiting" for money that is never coming.
     *
     * @param  list<string>  $answered references Flutterwave replied to
     */
    private function closeAbandonedRequests(Wallet $wallet, array $answered): int
    {
        if ($answered === []) {
            return 0;
        }

        $closed = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('status', 'pending')
            ->whereIn('reference', $answered)
            ->where('created_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            ->update([
                'status' => 'failed',
                'description' => 'No transfer arrived for this funding request. Generate a new account number to try again.',
                'updated_at' => now(),
            ]);

        if ($closed > 0) {
            Log::info('Abandoned wallet funding requests were closed out.', [
                'wallet_id' => $wallet->id,
                'closed' => $closed,
            ]);
        }

        return $closed;
    }

    /**
     * Whether this wallet still has a deposit the customer was told to send.
     */
    private function isAwaiting(Wallet $wallet): bool
    {
        return WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('status', 'pending')
            ->where('channel', 'like', 'flutterwave%')
            ->exists();
    }

    /**
     * References worth asking Flutterwave about: funding requests the customer
     * started but that never came back credited, plus the account numbers they
     * were given for bank transfers.
     *
     * @return list<array{ref:string,channel:string,accounts:list<string>}>
     */
    private function candidates(User $user, Wallet $wallet): array
    {
        $candidates = [];

        $pending = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('status', 'pending')
            ->where('channel', 'like', 'flutterwave%')
            ->latest('id')
            ->limit(self::MAX_REFERENCES)
            ->get();

        foreach ($pending as $row) {
            $account = trim((string) (($row->meta ?? [])['virtual_account_number'] ?? ''));

            $candidates[] = [
                'ref' => (string) $row->reference,
                'channel' => (string) ($row->channel ?: 'flutterwave'),
                'accounts' => $account !== '' ? [$account] : [],
            ];
        }

        $meta = (array) ($user->virtual_account_metadata ?? []);
        $permanentAccount = trim((string) ($user->virtual_account_number ?? ''));
        $temporary = (array) ($meta['temporary_virtual_account'] ?? []);

        foreach ([
            ['ref' => trim((string) ($meta['tx_ref'] ?? '')), 'account' => $permanentAccount],
            [
                'ref' => trim((string) ($temporary['tx_ref'] ?? '')),
                'account' => trim((string) ($temporary['account_number'] ?? '')),
            ],
        ] as $account) {
            if ($account['ref'] === '' && $account['account'] === '') {
                continue;
            }

            $candidates[] = [
                'ref' => $account['ref'] !== '' ? $account['ref'] : $account['account'],
                'channel' => 'flutterwave_virtual_account',
                'accounts' => $account['account'] !== '' ? [$account['account']] : [],
            ];
        }

        // Accounts this customer was given and then replaced. A transfer already
        // on its way to one of these has no other route back to their wallet.
        foreach ((array) ($meta['previous_virtual_accounts'] ?? []) as $old) {
            $old = (array) $old;
            $account = trim((string) ($old['account_number'] ?? ''));
            $ref = trim((string) ($old['tx_ref'] ?? ''));

            if ($account === '' && $ref === '') {
                continue;
            }

            $candidates[] = [
                'ref' => $ref !== '' ? $ref : $account,
                'channel' => 'flutterwave_virtual_account',
                'accounts' => $account !== '' ? [$account] : [],
            ];
        }

        // Funding requests that were closed out as abandoned still get asked about.
        // A bank transfer that took longer than the site was willing to wait is the
        // single most common way a customer's money arrives and is never seen again.
        $closed = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'credit')
            ->where('status', 'failed')
            ->where('channel', 'like', 'flutterwave%')
            ->where('updated_at', '>=', now()->subDays(self::ABANDONED_LOOKBACK_DAYS))
            ->latest('id')
            ->limit(self::MAX_REFERENCES)
            ->get();

        foreach ($closed as $row) {
            $account = trim((string) (($row->meta ?? [])['virtual_account_number'] ?? ''));

            $candidates[] = [
                'ref' => (string) $row->reference,
                'channel' => (string) ($row->channel ?: 'flutterwave'),
                'accounts' => $account !== '' ? [$account] : [],
            ];
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            if ($candidate['ref'] === '' || isset($unique[$candidate['ref']])) {
                continue;
            }
            $unique[$candidate['ref']] = $candidate;
        }

        return array_slice(array_values($unique), 0, self::MAX_CANDIDATES);
    }

    /**
     * The successful Flutterwave charges behind one reference. The transaction
     * list is asked first because a permanent account can receive many transfers
     * under the same reference; verifying a reference directly is kept as the
     * fallback for a single charge that the list does not return.
     *
     * @param  array{ref:string,channel:string,accounts:list<string>}  $candidate
     * @return list<array<string, mixed>>
     */
    private function settledCharges(array $candidate): array
    {
        $charges = [];

        $list = $this->flutterwave->findTransactions([
            'tx_ref' => $candidate['ref'],
            'status' => 'successful',
            'count' => 20,
        ]);

        if ($list->successful()) {
            foreach ((array) $list->json('data', []) as $row) {
                $charge = $this->verifiedCharge(trim((string) ($row['id'] ?? '')));
                if ($charge !== null && $this->isOurDeposit($charge, $candidate)) {
                    $charges[] = $charge;
                }
            }

            // Flutterwave answered us. An empty list is an honest "nothing has
            // landed under this reference yet", not a problem to re-ask about.
            return $charges;
        }

        Log::warning('Flutterwave deposit list was refused, falling back to a direct verify.', [
            'reference' => $candidate['ref'],
            'status' => $list->status(),
        ]);

        if ($charges !== []) {
            return $charges;
        }

        $charge = $this->verifiedCharge($candidate['ref']);

        return $charge !== null && $this->isOurDeposit($charge, $candidate) ? [$charge] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedCharge(string $idOrReference): ?array
    {
        if ($idOrReference === '') {
            return null;
        }

        $verify = $this->flutterwave->verifyTransaction($idOrReference);
        if (!$verify->successful()) {
            return null;
        }

        $charge = (array) $verify->json('data', []);
        $status = (string) ($charge['status'] ?? '');

        if (!$this->flutterwave->isSuccessfulChargeStatus($status)) {
            return null;
        }

        if (strtoupper((string) ($charge['currency'] ?? 'NGN')) !== 'NGN') {
            Log::warning('Flutterwave deposit check skipped a charge in another currency.', [
                'reference' => $idOrReference,
                'currency' => $charge['currency'] ?? null,
            ]);

            return null;
        }

        return $charge;
    }

    /**
     * Only ever bank money this reference can be proved to be ours: the transfer
     * must carry the reference we sent, or arrive in an account we handed out.
     *
     * @param  array<string, mixed>  $charge
     * @param  array{ref:string,channel:string,accounts:list<string>}  $candidate
     */
    private function isOurDeposit(array $charge, array $candidate): bool
    {
        if (trim((string) ($charge['tx_ref'] ?? '')) === $candidate['ref']) {
            return true;
        }

        $account = $this->funding->chargeAccountNumber($charge);

        return $account !== '' && in_array($account, $candidate['accounts'], true);
    }
}
