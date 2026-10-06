<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;

class WalletController extends Controller
{
    public function fundForm()
    {
        $user = auth()->user();
        $wallet = $user->wallet;
        $virtualAccountMeta = (array) ($user?->virtual_account_metadata ?? []);
        $temporaryAccount = (array) ($virtualAccountMeta['temporary_virtual_account'] ?? []);

        $balanceKobo = $wallet?->balance ?? 0;

        return view('wallet.fund', [
            'walletBalanceKobo' => $balanceKobo,
            'walletBalanceNaira' => $balanceKobo / 100,
            'user' => $user,
            // Optional: bank details (you can add these settings later in admin)
            'bank_name' => setting('bank_name', ''),
            'bank_account_name' => setting('bank_account_name', ''),
            'bank_account_number' => setting('bank_account_number', ''),
            'funding_fee_naira' => (float) setting('wallet_funding_fee', 50),
            'virtual_account' => [
                'account_number' => $user?->virtual_account_number,
                'account_name' => $user?->virtual_account_name,
                'bank_name' => $user?->virtual_account_bank,
                'assigned_at' => $user?->virtual_account_assigned_at,
            ],
            'temporary_virtual_account' => $temporaryAccount,
        ]);
    }

    public function transactions()
    {
        $user = auth()->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            abort(400, 'Wallet not found for this user.');
        }

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest()
            ->paginate(20);

        $balanceKobo = $wallet->balance ?? 0;

        return view('wallet.transactions', [
            'walletBalanceKobo' => $balanceKobo,
            'walletBalanceNaira' => $balanceKobo / 100,
            'transactions' => $transactions,
        ]);
    }
}
