<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.users', compact('users', 'search'));
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

    private function generateTemporaryPassword(): string
    {
        $prefix = Str::upper(Str::random(4));
        $digits = (string) random_int(1000, 9999);
        $suffix = Str::lower(Str::random(4));

        return $prefix.$digits.$suffix;
    }
}
