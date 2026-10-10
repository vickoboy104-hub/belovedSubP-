<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReferralController extends Controller
{
    private const REFERRAL_CHANNELS = ['referral_commission', 'referral_withdrawal'];

    /** Service types the admin can price commission for => customer-facing label. */
    private const RATE_LABELS = [
        'airtime' => 'Airtime',
        'data' => 'Data',
        'cable' => 'TV / Cable',
        'electricity' => 'Electricity',
        'exam' => 'Education / Exam PIN',
        'recharge_card' => 'Recharge PIN',
        'premium' => 'Premium Apps',
    ];

    public function visit(Request $request, string $code): RedirectResponse
    {
        $normalized = strtoupper(trim($code));
        $referrerExists = $normalized !== '' && User::query()->where('referral_code', $normalized)->exists();

        if (!$referrerExists) {
            return redirect()->route('register')->withErrors([
                'ref' => 'That referral code is not valid.',
            ]);
        }

        $request->session()->put('referral_code', $normalized);

        return redirect()
            ->route('register', ['ref' => $normalized])
            ->with('success', 'Referral code applied. Complete registration to continue.');
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $code = $user->ensureReferralCode();

        $invited = User::query()
            ->where('referred_by_user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'first_name', 'last_name', 'name', 'phone', 'referral_qualified_at', 'created_at']);

        $activity = $user->notifications()
            ->latest()
            ->get()
            ->filter(fn ($notification) => in_array(
                (string) ($notification->data['channel'] ?? ''),
                self::REFERRAL_CHANNELS,
                true
            ))
            ->take(20)
            ->map(fn ($notification) => [
                'label' => (string) ($notification->data['title'] ?? 'Referral activity'),
                'message' => (string) ($notification->data['message'] ?? ''),
                'amount_kobo' => (int) ($notification->data['amount_kobo'] ?? 0),
                'is_credit' => ($notification->data['channel'] ?? '') === 'referral_commission',
                'at' => $notification->created_at,
            ]);

        $defaultPercent = (float) setting('referral_default_percent', 1);
        $serviceRates = collect(self::RATE_LABELS)
            ->map(fn (string $label, string $type) => [
                'label' => $label,
                'percent' => (float) setting('referral_percent_' . $type, $defaultPercent),
                'enabled' => (string) setting('referral_enabled_' . $type, '1') === '1',
            ])
            ->filter(fn (array $rate) => $rate['enabled'] && $rate['percent'] > 0)
            ->values()
            ->all();

        return view('referral.index', [
            'user' => $user,
            'referralCode' => $code,
            'referralLink' => $user->referralLink(),
            'whatsAppShareLink' => $this->shareLink($user->referralLink(), $user->first_name ?: $user->name),
            'balanceKobo' => (int) ($user->referral_earnings_balance ?? 0),
            'totalKobo' => (int) ($user->referral_earnings_total ?? 0),
            'withdrawnKobo' => (int) ($user->referral_earnings_withdrawn ?? 0),
            'invitedCount' => $invited->count(),
            'qualifiedCount' => $invited->whereNotNull('referral_qualified_at')->count(),
            'invitedUsers' => $invited->take(12),
            'activity' => $activity,
            'serviceRates' => $serviceRates,
            'systemEnabled' => (string) setting('referral_system_enabled', '1') === '1',
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        $user->ensureReferralCode();

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
                    throw new \RuntimeException('You do not have that much in referral earnings.');
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

    private function shareLink(string $referralLink, string $firstName): string
    {
        $message = 'Hi ' . trim($firstName) . ", join me on BelovedSubP and get airtime, data, TV and electricity bills at a discount. Use my link: " . $referralLink;

        return 'https://wa.me/?text=' . rawurlencode($message);
    }

    private function generateUniqueWalletReference(): string
    {
        do {
            $candidate = 'REFWD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
        } while (WalletTransaction::query()->where('reference', $candidate)->exists());

        return $candidate;
    }
}
