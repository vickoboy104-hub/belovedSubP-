<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Support\Facades\Log;

/**
 * The only place wallet balances move. Purchases and the admin fulfilment queue
 * both come through here so the transaction row, the balance-before/after pair
 * the receipt reads, and the owner notification always agree.
 */
class WalletLedger
{
    public function debit(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $beforeKobo = (int) ($wallet->balance ?? 0);
        if ($beforeKobo < $amountKobo) {
            throw new \RuntimeException('Insufficient wallet balance.');
        }

        $this->move($wallet, 'debit', $amountKobo, $reference, $description, 'purchase', $beforeKobo, $beforeKobo - $amountKobo);
    }

    public function credit(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $beforeKobo = (int) ($wallet->balance ?? 0);

        $this->move($wallet, 'credit', $amountKobo, $reference, $description, 'refund', $beforeKobo, $beforeKobo + $amountKobo);
    }

    private function move(
        Wallet $wallet,
        string $type,
        int $amountKobo,
        string $reference,
        string $description,
        string $channel,
        int $beforeKobo,
        int $afterKobo,
    ): void {
        $wallet->balance = $afterKobo;
        $wallet->save();

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
