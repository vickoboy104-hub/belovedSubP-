<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Manual funding request:
     * Creates a pending credit transaction (does NOT credit wallet balance yet).
     * Admin can approve later.
     */
    public function fundSubmit(Request $request)
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $user = auth()->user();
        $wallet = $user->wallet;

        if (!$wallet) {
            abort(400, 'Wallet not found for this user.');
        }

        $amountKobo = (int) round(((float) $request->amount) * 100);

        $reference = 'FUND_' . strtoupper(Str::random(12));

        DB::beginTransaction();
        try {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amountKobo,
                'reference' => $reference,
                'status' => 'pending',
                'channel' => 'manual_funding',
                'description' => 'Wallet funding request',
                'meta' => [
                    'note' => $request->note,
                    'requested_by_user_id' => $user->id,
                ],
            ]);

            DB::commit();
            return redirect()
                ->route('wallet.transactions')
                ->with('success', 'Funding request submitted! Please wait for admin confirmation.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
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
