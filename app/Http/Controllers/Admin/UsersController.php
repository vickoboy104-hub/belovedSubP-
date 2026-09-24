<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($userQuery) use ($like) {
                    $userQuery
                        ->where('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            })
            ->with('wallet')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users', compact('users', 'search'));
    }

    public function show(User $user)
    {
        $user->load(['wallet', 'referrer']);

        $walletTransactions = $user->wallet
            ? $user->wallet->transactions()->latest()->paginate(12, ['*'], 'wallet_page')
            : collect();

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(10, ['*'], 'orders_page');

        $referralsCount = User::query()->where('referred_by_user_id', $user->id)->count();
        $qualifiedReferralsCount = User::query()
            ->where('referred_by_user_id', $user->id)
            ->whereNotNull('referral_qualified_at')
            ->count();

        return view('admin.user-show', compact(
            'user',
            'walletTransactions',
            'orders',
            'referralsCount',
            'qualifiedReferralsCount',
        ));
    }

    public function updateProfile(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'virtual_account_provider' => ['nullable', 'string', 'max:255'],
            'virtual_account_bank' => ['nullable', 'string', 'max:255'],
            'virtual_account_name' => ['nullable', 'string', 'max:255'],
            'virtual_account_number' => ['nullable', 'string', 'max:255'],
        ]);

        $user->fill([
            'name' => $data['name'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'],
        ]);

        foreach (['virtual_account_provider', 'virtual_account_bank', 'virtual_account_name', 'virtual_account_number'] as $field) {
            if (Schema::hasColumn('users', $field)) {
                $user->{$field} = $data[$field] ?? null;
            }
        }

        $user->save();

        return back()->with('success', 'User details updated successfully.');
    }

    public function updateDiscount(Request $request, User $user)
    {
        $data = $request->validate([
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (!Schema::hasColumn('users', 'discount_percent')) {
            Log::error('Cannot update user discount: users.discount_percent column is missing.');

            return back()->with('error', 'Discount column is missing. Please run database migrations.');
        }

        $discount = isset($data['discount_percent']) ? (float) $data['discount_percent'] : 0.0;
        try {
            $user->discount_percent = $discount;
            $user->save();
        } catch (\Throwable $e) {
            Log::error('Failed to update user discount.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not update discount right now. Please try again.');
        }

        return back()->with('success', 'Discount updated successfully.');
    }

    public function updateAdmin(Request $request, User $user)
    {
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $makeAdmin = (bool) $data['is_admin'];

        if (!$makeAdmin && $user->id === auth()->id()) {
            return back()->with('error', 'You cannot remove your own admin access.');
        }

        if (!$makeAdmin && $user->is_admin) {
            $adminCount = User::where('is_admin', true)->count();
            if ($adminCount <= 1) {
                return back()->with('error', 'You cannot remove the last admin user.');
            }
        }

        $user->is_admin = $makeAdmin;
        $user->save();

        return back()->with('success', 'Admin role updated successfully.');
    }

    public function resetPassword(User $user)
    {
        $temporaryPassword = $this->generateTemporaryPassword();

        $user->password = Hash::make($temporaryPassword);
        $user->remember_token = Str::random(60);
        $user->save();

        return back()->with('success', "Temporary password for {$user->email}: {$temporaryPassword}");
    }

    public function fundWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $amountKobo = (int) round(((float) $data['amount']) * 100);
        if ($amountKobo <= 0) {
            return back()->with('error', 'Invalid funding amount.');
        }

        try {
            DB::transaction(function () use ($user, $amountKobo, $data): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $wallet = $lockedUser->wallet()->lockForUpdate()->first();

                if (!$wallet) {
                    throw new \RuntimeException('User wallet not found.');
                }

                $beforeBalanceKobo = (int) ($wallet->balance ?? 0);
                $afterBalanceKobo = $beforeBalanceKobo + $amountKobo;

                $wallet->balance = $afterBalanceKobo;
                $wallet->save();

                $reference = 'ADMINFUND-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'credit',
                    'amount' => $amountKobo,
                    'reference' => $reference,
                    'status' => 'success',
                    'channel' => 'admin_manual_credit',
                    'description' => 'Admin wallet funding',
                    'meta' => [
                        'admin_user_id' => auth()->id(),
                        'note' => trim((string) ($data['note'] ?? '')),
                        'balance_before_kobo' => $beforeBalanceKobo,
                        'balance_after_kobo' => $afterBalanceKobo,
                    ],
                ]);

                try {
                    $lockedUser->notify(new UserWalletActivityNotification(
                        'Wallet Funded by Admin',
                        'N' . number_format($amountKobo / 100, 2) . ' was credited to your wallet by admin support.',
                        [
                            'type' => 'credit',
                            'channel' => 'admin_manual_credit',
                            'amount_kobo' => $amountKobo,
                            'balance_before_kobo' => $beforeBalanceKobo,
                            'balance_after_kobo' => $afterBalanceKobo,
                        ]
                    ));
                } catch (\Throwable $e) {
                    // Ignore notification failure to avoid interrupting manual funding.
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Admin manual wallet funding failed.', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Unable to fund wallet right now.');
        }

        return back()->with('success', 'Wallet funded successfully.');
    }

    public function adjustWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'adjustment_type' => ['required', Rule::in(['credit', 'debit', 'set'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $amountKobo = (int) round(((float) $data['amount']) * 100);

        try {
            DB::transaction(function () use ($user, $data, $amountKobo): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $wallet = $lockedUser->wallet()->lockForUpdate()->first();

                if (!$wallet) {
                    throw new \RuntimeException('User wallet not found.');
                }

                $beforeBalanceKobo = (int) ($wallet->balance ?? 0);
                $mode = (string) $data['adjustment_type'];

                if ($mode === 'set') {
                    $afterBalanceKobo = $amountKobo;
                    $deltaKobo = $afterBalanceKobo - $beforeBalanceKobo;
                } elseif ($mode === 'credit') {
                    $deltaKobo = $amountKobo;
                    $afterBalanceKobo = $beforeBalanceKobo + $deltaKobo;
                } else {
                    $deltaKobo = -$amountKobo;
                    $afterBalanceKobo = $beforeBalanceKobo + $deltaKobo;
                }

                if ($afterBalanceKobo < 0) {
                    throw new \RuntimeException('Wallet balance cannot go below zero.');
                }

                if ($deltaKobo === 0) {
                    throw new \RuntimeException('No wallet balance change was made.');
                }

                $wallet->balance = $afterBalanceKobo;
                $wallet->save();

                $transactionType = $deltaKobo > 0 ? 'credit' : 'debit';
                $reference = 'ADMINADJ-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => $transactionType,
                    'amount' => abs($deltaKobo),
                    'reference' => $reference,
                    'status' => 'success',
                    'channel' => 'admin_wallet_adjustment',
                    'description' => 'Admin wallet balance adjustment',
                    'meta' => [
                        'admin_user_id' => auth()->id(),
                        'adjustment_mode' => $mode,
                        'note' => trim((string) ($data['note'] ?? '')),
                        'balance_before_kobo' => $beforeBalanceKobo,
                        'balance_after_kobo' => $afterBalanceKobo,
                    ],
                ]);

                try {
                    $verb = $transactionType === 'credit' ? 'credited to' : 'debited from';
                    $lockedUser->notify(new UserWalletActivityNotification(
                        'Wallet Adjusted by Admin',
                        'N' . number_format(abs($deltaKobo) / 100, 2) . ' was ' . $verb . ' your wallet by admin support.',
                        [
                            'type' => $transactionType,
                            'channel' => 'admin_wallet_adjustment',
                            'reference' => $reference,
                            'amount_kobo' => abs($deltaKobo),
                            'balance_before_kobo' => $beforeBalanceKobo,
                            'balance_after_kobo' => $afterBalanceKobo,
                        ]
                    ));
                } catch (\Throwable $e) {
                    // Ignore notification failure to avoid interrupting admin adjustment.
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Admin wallet adjustment failed.', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Unable to adjust wallet right now.');
        }

        return back()->with('success', 'Wallet adjusted successfully.');
    }

    private function generateTemporaryPassword(): string
    {
        $prefix = Str::upper(Str::random(4));
        $digits = (string) random_int(1000, 9999);
        $suffix = Str::lower(Str::random(4));

        return $prefix.$digits.$suffix;
    }
}
