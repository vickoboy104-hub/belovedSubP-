<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use App\Services\AdminActivityRecorder;
use App\Services\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(
        private readonly WalletLedger $ledger,
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($userQuery) use ($like, $search) {
                    // An id is the one identifier that cannot belong to somebody
                    // else, so a number that names a real account means that account
                    // and nothing else. A longer digit string - a phone number - is
                    // not an id anybody holds, and still searches the fuzzy fields.
                    if (ctype_digit($search) && (int) $search > 0 && User::query()->whereKey((int) $search)->exists()) {
                        $userQuery->where('id', (int) $search);

                        return;
                    }

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

        // A gateway credit that never arrived stays open until an admin says the
        // money was paid elsewhere, and that decision belongs on its own shelf, not
        // inside a reading table: whichever page of the ledger the admin happens to
        // be on, every one of this customer's open requests is listed here.
        $openFunding = $user->wallet
            ? $user->wallet->transactions()
                ->where('type', 'credit')
                ->where('channel', 'like', 'flutterwave%')
                ->whereIn('status', ['pending', 'failed'])
                ->latest()
                ->limit(50)
                ->get()
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
            'openFunding',
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

        $watched = [
            'name', 'first_name', 'last_name', 'phone', 'email',
            'virtual_account_provider', 'virtual_account_bank', 'virtual_account_name', 'virtual_account_number',
        ];
        $before = $user->only($watched);

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

        // The fact that an admin rewrote a customer's identity details is the
        // finding. The old values stay out of the trail: this site's own audit
        // should not become a second copy of every customer's phone number.
        $this->audit->success('customer', 'customer.profile_updated', 'Customer details edited by an administrator.', [
            'target' => ['user', $user->id],
            'changes' => ['changed_fields' => array_keys(array_diff_assoc($before, $user->only($watched)))],
        ]);

        return back()->with('success', 'User details updated successfully.');
    }

    public function updateDiscount(Request $request, User $user)
    {
        $data = $request->validate([
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (!Schema::hasColumn('users', 'discount_percent')) {
            Log::error('Cannot update user discount: users.discount_percent column is missing.');

            $this->audit->recordQuietly('failed', 'customer', 'customer.discount_changed', 'Discount change could not be saved.', [
                'target' => ['user', $user->id],
                'reason' => 'The discount_percent column is missing on this database.',
            ]);

            return back()->with('error', 'Discount column is missing. Please run database migrations.');
        }

        $discount = isset($data['discount_percent']) ? (float) $data['discount_percent'] : 0.0;
        $previous = (float) ($user->discount_percent ?? 0);
        try {
            $user->discount_percent = $discount;
            $user->save();
        } catch (\Throwable $e) {
            Log::error('Failed to update user discount.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->audit->recordQuietly('failed', 'customer', 'customer.discount_changed', 'Discount change did not save.', [
                'target' => ['user', $user->id],
                'reason' => 'The database refused the write.',
                'changes' => ['attempted_percent' => $discount],
            ]);

            return back()->with('error', 'Could not update discount right now. Please try again.');
        }

        // A discount changes what a customer is charged, so it is a money-relevant
        // edit even though no balance moved. The number is recorded, not a secret.
        $this->audit->success('customer', 'customer.discount_changed', 'Customer discount rate changed.', [
            'target' => ['user', $user->id],
            'changes' => ['before_percent' => $previous, 'after_percent' => $discount],
        ]);

        return back()->with('success', 'Discount updated successfully.');
    }

    public function updateAdmin(Request $request, User $user)
    {
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $makeAdmin = (bool) $data['is_admin'];
        $actingId = (int) $request->user()->id;

        $recordRefusal = function (string $reason) use ($request, $user, $makeAdmin): void {
            $this->audit->recordQuietly('denied', 'customer', 'admin.access_changed', 'Attempt to change administrator access was refused.', [
                'target' => ['user', $user->id],
                'reason' => $reason,
                'changes' => ['requested_is_admin' => $makeAdmin],
            ]);
        };

        // Nobody hands themselves the admin flag. Any admin can already create a
        // customer account, so self-promotion needs no second signature today -
        // but it must be visible as a privilege someone granted themselves.
        if ($makeAdmin && $user->id === $actingId) {
            $recordRefusal('An account cannot grant itself administrator access.');

            return back()->with('error', 'You cannot give your own account administrator access.');
        }

        if (!$makeAdmin && $user->id === $actingId) {
            $recordRefusal('An account cannot remove its own administrator access.');

            return back()->with('error', 'You cannot remove your own admin access.');
        }

        // The owner account is named by the owner, in .env, and is not guessable
        // from here. Locking that account out of the audit would leave the trail
        // with nobody who can read it, so it is refused rather than assumed.
        $ownerIds = array_map('intval', (array) config('audit.owner_user_ids'));

        if (!$makeAdmin && in_array((int) $user->id, $ownerIds, true)) {
            $recordRefusal('That account is the named audit owner.');

            return back()->with('error', 'You cannot remove administrator access from the account that owns the audit trail.');
        }

        /* A Master Admin is protected whoever the actor is, including another Master.
           The role is the owner's decision, written in the server's configuration,
           and a click that could switch it off would make every permission in the
           system revocable by accident - or by the next account that gets here.
           Demotion also cannot happen by silence: if the environment ever stops
           naming this account, the flag stays on and the owner fixes the list. */
        $masterIds = \App\Support\AdminPermissions::masterUserIds();

        if (!$makeAdmin && in_array((int) $user->id, $masterIds, true)) {
            $recordRefusal('That account is a Master Admin named in configuration.');

            return back()->with('error', 'A Master Admin cannot be deactivated from this screen. The owner names Master Admins in the server configuration.');
        }

        if (!$makeAdmin && $user->is_admin) {
            $adminCount = User::where('is_admin', true)->count();
            if ($adminCount <= 1) {
                $recordRefusal('It is the last administrator account.');

                return back()->with('error', 'You cannot remove the last admin user.');
            }
        }

        $user->is_admin = $makeAdmin;
        $user->save();

        /* Promotion turns a screen on, it does not decide what that screen may do.
           A newly promoted administrator is given nothing here: if promotion also
           handed out the full set, then anybody holding admins.grant could create a
           customer account, promote it, and have effectively granted every permission
           in the system through a route that is not the permissions page. The grants
           are named one at a time by a Master Admin, on their own page. */
        $this->audit->success('customer', 'admin.access_changed', $makeAdmin
            ? 'Administrator access granted to an account, with no permissions yet.'
            : 'Administrator access removed from an account.', [
            'target' => ['user', $user->id],
            'changes' => ['is_admin' => $makeAdmin],
        ]);

        return back()->with('success', $makeAdmin
            ? 'Administrator access granted. The account opens with no permissions - a Master Admin names what it may do on the permissions page.'
            : 'Admin role updated successfully.');
    }

    public function resetPassword(User $user)
    {
        $temporaryPassword = $this->generateTemporaryPassword();

        $user->password = Hash::make($temporaryPassword);
        $user->remember_token = Str::random(60);
        $user->save();

        // The password itself is never recorded, and neither is the temporary one:
        // the trail says the credential was reset, which is the whole finding.
        $this->audit->success('customer', 'customer.password_reset', 'Customer password reset by an administrator.', [
            'target' => ['user', $user->id],
        ]);

        return back()->with('success', "Temporary password for {$user->email}: {$temporaryPassword}");
    }

    public function fundWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            // A credit that moves a balance with no reason written against it is
            // unauditable afterwards, and "why" is the only part a later reader
            // cannot reconstruct. Required before any money moves.
            'note' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $amountKobo = (int) round(((float) $data['amount']) * 100);
        if ($amountKobo <= 0) {
            $this->audit->recordQuietly('failed', 'wallet', 'wallet.fund', 'Hand credit refused: the amount was not valid.', [
                'target' => ['user', $user->id],
                'reason' => 'The amount did not convert to a positive number of kobo.',
            ]);

            return back()->with('error', 'Invalid funding amount.');
        }

        try {
            DB::transaction(function () use ($user, $amountKobo, $data, $request): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $wallet = $lockedUser->wallet()->lockForUpdate()->first();

                if (!$wallet) {
                    throw new \RuntimeException('User wallet not found.');
                }

                [$beforeBalanceKobo, $afterBalanceKobo] = $this->ledger->shift($wallet, 'credit', $amountKobo);
                $wallet->balance = $afterBalanceKobo;

                $reference = $this->uniqueReference('ADMINFUND');
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

                // Written here rather than after the transaction so the entry and
                // the balance move commit together, or neither does. What the
                // customer sent is recorded, never the customer's name: this table
                // should not become a second copy of everyone's details.
                $this->audit->record('success', 'wallet', 'wallet.fund', 'Credited '.number_format($amountKobo / 100, 2).' to this wallet by hand.', [
                    'actor' => $request->user(),
                    'target' => ['wallet', $wallet->id],
                    'reference' => $reference,
                    'reason' => trim((string) $data['note']),
                    'changes' => [
                        'amount_kobo' => $amountKobo,
                        'balance_before_kobo' => $beforeBalanceKobo,
                        'balance_after_kobo' => $afterBalanceKobo,
                        // How many gateway credits on this wallet are still open.
                        // The hand credit is the one path that can pay the same
                        // money twice, so the count is the evidence a later review
                        // reads. It records nothing about what to do with it.
                        'open_gateway_credits' => WalletTransaction::query()
                            ->where('wallet_id', $wallet->id)
                            ->where('type', 'credit')
                            ->where('channel', 'like', 'flutterwave%')
                            ->whereIn('status', ['pending', 'failed'])
                            ->count(),
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
            // Outside the transaction on purpose: the rollback undoes anything
            // written inside it, and a refusal still needs to be visible.
            $this->audit->recordQuietly('failed', 'wallet', 'wallet.fund', 'Hand credit did not go through.', [
                'target' => ['user', $user->id],
                'reason' => $e->getMessage(),
                'changes' => ['amount_kobo' => $amountKobo],
            ]);

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Admin manual wallet funding failed.', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            $this->audit->recordQuietly('failed', 'wallet', 'wallet.fund', 'Hand credit failed to save.', [
                'target' => ['user', $user->id],
                'reason' => 'The write was rolled back.',
                'changes' => ['amount_kobo' => $amountKobo],
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
            // Same rule as the hand credit: no balance moves without a reason a
            // later reader can hold the author to.
            'note' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $amountKobo = (int) round(((float) $data['amount']) * 100);

        /* One route, three powers. The form field that decides the direction is read
           before anything is locked, because an account allowed to take an over-credit
           back must not thereby be able to hand one out, and a person allowed to name
           an exact balance is the only one who can move a wallet in both directions at
           once. The route cannot see this: the mode is in the body. */
        $mode = (string) $data['adjustment_type'];
        $requiredPermission = match ($mode) {
            'credit' => 'wallet.credit',
            'debit' => 'wallet.debit',
            default => 'wallet.balance.set',
        };

        if (!$request->user()->hasAdminPermission($requiredPermission)) {
            $this->audit->recordQuietly('denied', 'wallet', 'wallet.adjustment.denied', 'Wallet adjustment refused: permission not held.', [
                'target' => ['user', $user->id],
                'reason' => 'Missing permission: '.$requiredPermission.'.',
                'changes' => ['mode' => $mode],
                'throttle_seconds' => 300,
            ]);

            return back()->with('error', 'You do not have permission to make that adjustment.');
        }

        try {
            DB::transaction(function () use ($user, $data, $amountKobo, $request, $mode): void {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $wallet = $lockedUser->wallet()->lockForUpdate()->first();

                if (!$wallet) {
                    throw new \RuntimeException('User wallet not found.');
                }

                $nowKobo = (int) DB::table('wallets')->where('id', $wallet->id)->value('balance');

                /* Set-to-a-number is still a movement: it is turned into the credit or
                   debit that reaches that number, so the write stays relative and two
                   admins adjusting the same wallet cannot overwrite each other's view
                   of what was there. */
                if ($mode === 'set') {
                    $deltaKobo = $amountKobo - $nowKobo;
                } elseif ($mode === 'credit') {
                    $deltaKobo = $amountKobo;
                } else {
                    $deltaKobo = -$amountKobo;
                }

                if ($deltaKobo === 0) {
                    throw new \RuntimeException('No wallet balance change was made.');
                }

                if ($nowKobo + $deltaKobo < 0) {
                    throw new \RuntimeException('Wallet balance cannot go below zero.');
                }

                $transactionType = $deltaKobo > 0 ? 'credit' : 'debit';
                [$beforeBalanceKobo, $afterBalanceKobo] = $this->ledger->shift($wallet, $transactionType, abs($deltaKobo));
                $wallet->balance = $afterBalanceKobo;

                $reference = $this->uniqueReference('ADMINADJ');

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

                $this->audit->record('success', 'wallet', 'wallet.'.$transactionType.'.adjustment', ucfirst($mode === 'set' ? 'Balance set' : $mode).' by '.number_format(abs($deltaKobo) / 100, 2).' as an admin adjustment.', [
                    'actor' => $request->user(),
                    'target' => ['wallet', $wallet->id],
                    'reference' => $reference,
                    'reason' => trim((string) $data['note']),
                    'changes' => [
                        'mode' => $mode,
                        'amount_kobo' => abs($deltaKobo),
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
            $this->audit->recordQuietly('failed', 'wallet', 'wallet.adjustment', 'Wallet adjustment did not go through.', [
                'target' => ['user', $user->id],
                'reason' => $e->getMessage(),
                'changes' => ['mode' => $data['adjustment_type'] ?? null, 'amount_kobo' => $amountKobo],
            ]);

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Admin wallet adjustment failed.', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            $this->audit->recordQuietly('failed', 'wallet', 'wallet.adjustment', 'Wallet adjustment failed to save.', [
                'target' => ['user', $user->id],
                'reason' => 'The write was rolled back.',
                'changes' => ['mode' => $data['adjustment_type'] ?? null, 'amount_kobo' => $amountKobo],
            ]);

            return back()->with('error', 'Unable to adjust wallet right now.');
        }

        return back()->with('success', 'Wallet adjusted successfully.');
    }

    private function uniqueReference(string $prefix): string
    {
        do {
            $reference = $prefix.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        } while (WalletTransaction::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function generateTemporaryPassword(): string
    {
        $prefix = Str::upper(Str::random(4));
        $digits = (string) random_int(1000, 9999);
        $suffix = Str::lower(Str::random(4));

        return $prefix.$digits.$suffix;
    }
}
