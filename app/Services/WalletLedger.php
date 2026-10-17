<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalance;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place wallet balances move. Purchases and the admin fulfilment queue
 * both come through here so the transaction row, the balance-before/after pair
 * the receipt reads, and the owner notification always agree.
 *
 * The balance is never computed in PHP. Two requests that each read "₦500 available"
 * and each try to spend it must not both succeed, so the money moves as an
 * `balance = balance ± n` statement that the database executes against whatever the
 * row actually holds at that moment, and a debit only lands if the row could still
 * cover it. What the ledger records is the balance the database settled on, read back
 * after that statement, so the receipt cannot describe a balance nobody ever had.
 */
class WalletLedger
{
    public function debit(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $this->move($wallet, 'debit', $amountKobo, $reference, $description, 'purchase');
    }

    public function credit(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $this->move($wallet, 'credit', $amountKobo, $reference, $description, 'refund');
    }

    /**
     * Move the balance and nothing else, for the callers that own the transaction and
     * write their own ledger row: the deposit sweep, admin funding, admin adjustments
     * and referral withdrawals. They take the pair this settled on so their receipt
     * describes the balance the database actually reached.
     *
     * @return array{0:int,1:int} balance before and after, in kobo
     */
    public function shift(Wallet $wallet, string $type, int $amountKobo): array
    {
        $amountKobo = $this->checked($amountKobo);
        $taking = $type === 'debit';
        $walletId = (int) $wallet->id;

        /* A zero movement is not a free pass for the database to report "nothing
           changed": MySQL returns zero affected rows when a value ends up where it
           started, which would look exactly like an unfunded wallet. */
        if ($amountKobo > 0) {
            $query = DB::table('wallets')->where('id', $walletId);
            if ($taking) {
                $query->where('balance', '>=', $amountKobo);
            }

            $moved = $query->update([
                'balance' => DB::raw($taking ? "balance - {$amountKobo}" : "balance + {$amountKobo}"),
                'updated_at' => now(),
            ]);

            if ($moved !== 1) {
                if ($taking) {
                    throw new InsufficientWalletBalance('Insufficient wallet balance.');
                }

                throw new \RuntimeException('The wallet this money belongs to no longer exists.');
            }
        }

        $afterKobo = (int) DB::table('wallets')->where('id', $walletId)->value('balance');

        return [$taking ? $afterKobo + $amountKobo : $afterKobo - $amountKobo, $afterKobo];
    }

    private function checked(int $amountKobo): int
    {
        if ($amountKobo < 0) {
            throw new \InvalidArgumentException('A wallet movement cannot be negative.');
        }

        return $amountKobo;
    }

    private function move(
        Wallet $wallet,
        string $type,
        int $amountKobo,
        string $reference,
        string $description,
        string $channel,
    ): void {
        /* Inside someone else's transaction the balance and the ledger row already
           rise or fall together. Outside one, they must not be separable. */
        if (DB::transactionLevel() > 0) {
            $this->write($wallet, $type, $amountKobo, $reference, $description, $channel);

            return;
        }

        DB::transaction(fn () => $this->write($wallet, $type, $amountKobo, $reference, $description, $channel));
    }

    private function write(
        Wallet $wallet,
        string $type,
        int $amountKobo,
        string $reference,
        string $description,
        string $channel,
    ): void {
        [$beforeKobo, $afterKobo] = $this->shift($wallet, $type, $this->checked($amountKobo));

        WalletTransaction::create([
            'wallet_id'   => $wallet->id,
            'type'        => $type,
            'amount'      => $amountKobo,
            'reference'   => $reference,
            'status'      => 'success',
            'channel'     => $channel,
            'description' => $description,
            'meta'        => [
                'balance_before_kobo' => $beforeKobo,
                'balance_after_kobo' => $afterKobo,
            ],
        ]);

        $wallet->balance = $afterKobo;

        $this->notifyOwner(
            $wallet,
            $type === 'debit' ? 'Wallet Debited' : 'Wallet Credited',
            'N'.number_format($amountKobo / 100, 2).' '.($type === 'debit' ? 'debited for transaction: ' : 'credited: ').$description,
            [
                'type' => $type,
                'amount_kobo' => $amountKobo,
                'reference' => $reference,
                'channel' => $channel,
                'balance_before_kobo' => $beforeKobo,
                'balance_after_kobo' => $afterKobo,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function notifyOwner(Wallet $wallet, string $title, string $message, array $payload): void
    {
        try {
            $user = $wallet->user()->first();
            if (!$user) {
                return;
            }

            $user->notify(new UserWalletActivityNotification($title, $message, $payload));
        } catch (\Throwable $e) {
            Log::warning('Wallet notification failed.', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
