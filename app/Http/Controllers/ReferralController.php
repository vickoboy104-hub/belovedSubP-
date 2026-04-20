<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    public function visit(Request $request, string $code): RedirectResponse
    {
        $normalized = strtoupper(trim($code));
        if ($normalized !== '') {
            $request->session()->put('referral_code', $normalized);
        }

        return redirect()
            ->route('register', ['ref' => $normalized])
            ->with('success', 'Referral code applied. Complete registration to continue.');
    }

    public function generate(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        if (trim((string) $user->referral_code) === '') {
            $user->referral_code = $this->generateUniqueReferralCode();
            $user->save();
        }

        return back()->with('success', 'Referral link generated successfully.');
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
        ]);

        $user = $request->user();
        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        $amountKobo = (int) round(((float) $validated['amount']) * 100);
        if ($amountKobo <= 0) {
            return back()->with('error', 'Invalid withdrawal amount.');
        }

        try {
            DB::transaction(function () use ($user, $amountKobo): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $wallet = $lockedUser->wallet()->lockForUpdate()->first();

                if (!$wallet) {
                    throw new \RuntimeException('Wallet not found.');
                }

                $availableKobo = (int) ($lockedUser->referral_earnings_balance ?? 0);
                if ($availableKobo < $amountKobo) {
                    throw new \RuntimeException('Insufficient referral earnings balance.');
                }

                $lockedUser->referral_earnings_balance = $availableKobo - $amountKobo;
                $lockedUser->referral_earnings_withdrawn = (int) ($lockedUser->referral_earnings_withdrawn ?? 0) + $amountKobo;
                $lockedUser->save();

                $beforeBalanceKobo = (int) ($wallet->balance ?? 0);
                $afterBalanceKobo = $beforeBalanceKobo + $amountKobo;
                $wallet->balance = $afterBalanceKobo;
                $wallet->save();

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'credit',
                    'amount' => $amountKobo,
                    'reference' => $this->generateUniqueWalletReference(),
                    'status' => 'success',
                    'channel' => 'referral_withdrawal',
                    'description' => 'Referral earnings withdrawal to wallet',
                    'meta' => [
                        'balance_before_kobo' => $beforeBalanceKobo,
                        'balance_after_kobo' => $afterBalanceKobo,
                        'source' => 'referral_earnings',
                    ],
                ]);

                try {
                    $lockedUser->notify(new UserWalletActivityNotification(
                        'Referral Withdrawal Credited',
                        'N' . number_format($amountKobo / 100, 2) . ' was moved from referral earnings to your wallet.',
                        [
                            'type' => 'credit',
                            'channel' => 'referral_withdrawal',
                            'amount_kobo' => $amountKobo,
                            'balance_before_kobo' => $beforeBalanceKobo,
                            'balance_after_kobo' => $afterBalanceKobo,
                        ]
                    ));
                } catch (\Throwable $e) {
                    // Ignore notification failure to avoid blocking wallet updates.
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Referral withdrawal failed. Please try again.');
        }

        return back()->with('success', 'Referral earnings withdrawn to wallet successfully.');
    }

    private function generateUniqueReferralCode(): string
    {
        do {
            $candidate = Str::upper(Str::random(8));
        } while (User::query()->where('referral_code', $candidate)->exists());

        return $candidate;
    }

    private function generateUniqueWalletReference(): string
    {
        do {
            $candidate = 'REFWD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
        } while (WalletTransaction::query()->where('reference', $candidate)->exists());

        return $candidate;
    }
}
