<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Services\WalletDepositSync;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletDepositSync $deposits,
    ) {
    }

    public function fundForm(Request $request)
    {
        $user = $request->user();

        // A bank transfer is only reported to this site by Flutterwave's webhook,
        // and a webhook cannot reach a machine Flutterwave cannot call. Looking
        // the deposit up here means money arriving is money shown.
        $automaticCheck = $this->deposits->sync($user);
        if ($automaticCheck['credited_kobo'] > 0) {
            $user->refresh();
        }

        $wallet = $user->wallet;
        $virtualAccountMeta = (array) ($user?->virtual_account_metadata ?? []);
        $temporaryAccount = (array) ($virtualAccountMeta['temporary_virtual_account'] ?? []);

        $balanceKobo = $wallet?->balance ?? 0;

        // Only the answer to the button the customer pressed is worth interrupting
        // them with; the quiet check that runs when the page opens stays quiet.
        $askedCheck = session('deposit_check');

        return view('wallet.fund', [
            'walletBalanceKobo' => $balanceKobo,
            'walletBalanceNaira' => $balanceKobo / 100,
            'user' => $user,
            // Optional: bank details (you can add these settings later in admin)
            'bank_name' => setting('bank_name', ''),
            'bank_account_name' => setting('bank_account_name', ''),
            'bank_account_number' => setting('bank_account_number', ''),
            'funding_fee_naira' => (float) setting('wallet_funding_fee', 50),
            'depositCheck' => (array) ($askedCheck ?? $automaticCheck),
            'depositCheckAsked' => $askedCheck !== null,
            'pendingDeposits' => $this->awaitingDeposits($wallet?->id),
            'virtual_account' => [
                'account_number' => $user?->virtual_account_number,
                'account_name' => $user?->virtual_account_name,
                'bank_name' => $user?->virtual_account_bank,
                'assigned_at' => $user?->virtual_account_assigned_at,
            ],
            'temporary_virtual_account' => $temporaryAccount,
        ]);
    }

    public function transactions(Request $request)
    {
        $user = $request->user();

        $check = $this->deposits->sync($user);
        if ($check['credited_kobo'] > 0) {
            $user->refresh();
        }

        $wallet = $user->wallet;

        if (!$wallet) {
            abort(400, 'Wallet not found for this user.');
        }

        // The history block searches and pages against the database, so a filter
        // can never look like it dropped rows the customer still owes money on.
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $query = WalletTransaction::where('wallet_id', $wallet->id);
        if ($search !== '') {
            $query->where(function ($filter) use ($search): void {
                $filter->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('channel', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $totalRecords = (clone $query)->count();
        $transactions = $query->latest()->paginate($perPage)->withQueryString();

        $balanceKobo = $wallet->balance ?? 0;
        $askedCheck = session('deposit_check');

        return view('wallet.transactions', [
            'walletBalanceKobo' => $balanceKobo,
            'walletBalanceNaira' => $balanceKobo / 100,
            'transactions' => $transactions,
            'search' => $search,
            'perPage' => $perPage,
            'totalRecords' => $totalRecords,
            'depositCheck' => (array) ($askedCheck ?? $check),
            'depositCheckAsked' => $askedCheck !== null,
            'pendingDeposits' => $this->awaitingDeposits($wallet->id),
        ]);
    }

    /**
     * Transfers the customer has been told to make and that have not landed yet.
     * A deposit this site has already closed out, or a credit that was never a
     * transfer request, is not something to keep a customer waiting on.
     *
     * @return \Illuminate\Support\Collection<int, WalletTransaction>
     */
    private function awaitingDeposits(?int $walletId)
    {
        if (!$walletId) {
            return collect();
        }

        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'credit')
            ->where('status', 'pending')
            ->where('channel', 'like', 'flutterwave%')
            ->latest('id')
            ->take(5)
            ->get();
    }
}
