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

    /** Each reference costs up to two API calls, so a check is kept small. */
    private const MAX_REFERENCES = 8;

    /**
     * How long a funding request is allowed to keep saying "still waiting" after
     * Flutterwave was asked about it and reported no transfer. A bank transfer
     * that was really sent is never lost by this: the account number it went to
     * is still asked about on every later check, and money found there writes
     * its own completed credit row.
     */
    private const ABANDONED_AFTER_MINUTES = 60;

    public function __construct(
        private readonly FlutterwaveService $flutterwave,
        private readonly WalletFundingService $funding,
    ) {
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

        $unique = [];
        foreach ($candidates as $candidate) {
            if ($candidate['ref'] === '' || isset($unique[$candidate['ref']])) {
                continue;
            }
            $unique[$candidate['ref']] = $candidate;
        }

        return array_values($unique);
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
