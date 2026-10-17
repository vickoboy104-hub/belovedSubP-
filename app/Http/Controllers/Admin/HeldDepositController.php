<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
use App\Services\AdminActivityRecorder;
use App\Services\WalletFundingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A bank transfer that this site can prove arrived, but cannot prove who sent it,
 * is held here rather than paid to whichever customer a lookup found first. The
 * transfer, the amount and the candidate customers are all recorded; a human reads
 * the payment and names the account it belongs to.
 */
class HeldDepositController extends Controller
{
    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $query = FlutterwaveHeldDeposit::query()
            ->with(['resolvedBy', 'resolvedWallet.user'])
            ->when(
                $request->input('status') === 'resolved',
                fn ($q) => $q->where('status', '!=', 'held'),
                fn ($q) => $q->where('status', 'held')
            )
            ->latest('id')
            ->paginate(20)
            ->appends($request->query());

        // The names an admin needs to choose between are the reason the deposit is
        // here, so they are read with the record rather than left as user ids.
        $ids = $query->getCollection()
            ->flatMap(fn (FlutterwaveHeldDeposit $held): array => (array) $held->holder_user_ids)
            ->unique()
            ->values()
            ->all();

        $candidates = $ids === []
            ? collect()
            : User::query()->with('wallet')->whereIn('id', $ids)->get()->keyBy('id');

        return view('admin.held-deposits', [
            'deposits' => $query,
            'candidates' => $candidates,
            'heldCount' => FlutterwaveHeldDeposit::query()->where('status', 'held')->count(),
            'resolvedCount' => FlutterwaveHeldDeposit::query()->where('status', '!=', 'held')->count(),
            'showResolved' => $request->input('status') === 'resolved',
        ]);
    }

    public function credit(Request $request, FlutterwaveHeldDeposit $deposit, WalletFundingService $funding)
    {
        // The choice is limited to the customers this transfer could actually have
        // come from. Somebody else's wallet is what the manual credit tool is for:
        // letting this screen name any account would rebuild the guess it replaced,
        // only slower and with a note attached to make it look decided.
        $candidates = array_map(
            static fn ($id): int => (int) $id,
            (array) $deposit->holder_user_ids
        );

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($candidates)],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        if (!$deposit->isHeld()) {
            $this->audit->recordQuietly('failed', 'wallet', 'held_deposit.credit', 'Attempt to release a held deposit that was already dealt with.', [
                'target' => ['held_deposit', $deposit->id],
                'reason' => 'Its status is '.$deposit->status.'.',
            ]);

            return back()->with('error', 'This deposit has already been dealt with.');
        }

        $customer = User::query()->findOrFail($validated['user_id']);
        $credited = $funding->creditHeldDepositTo($deposit, $customer, $request->user(), $validated['note']);

        if ($credited <= 0) {
            $this->audit->recordQuietly('failed', 'wallet', 'held_deposit.credit', 'Nothing was credited: this charge has already been paid out.', [
                'target' => ['held_deposit', $deposit->id],
                'changes' => ['chosen_user_id' => $customer->id],
            ]);

            return back()->with('error', 'Nothing was credited: this charge has already been paid out somewhere.');
        }

        $this->audit->recordQuietly('success', 'wallet', 'held_deposit.credit', 'Released a held deposit: credited '.number_format($credited / 100, 2).' to a customer.', [
            'target' => ['held_deposit', $deposit->id],
            'reason' => $validated['note'],
            'changes' => ['credited_kobo' => $credited, 'chosen_user_id' => $customer->id],
        ]);

        return back()->with('success', 'Credited '.number_format($credited / 100, 2).' to '.$customer->name.'.');
    }

    public function dismiss(Request $request, FlutterwaveHeldDeposit $deposit)
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $dismissed = FlutterwaveHeldDeposit::query()
            ->whereKey($deposit->id)
            ->where('status', 'held')
            ->update([
                'status' => 'dismissed',
                'resolution_note' => $validated['note'],
                'resolved_by_user_id' => $request->user()->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($dismissed !== 1) {
            $this->audit->recordQuietly('failed', 'transaction_review', 'held_deposit.dismiss', 'Attempt to dismiss a held deposit that was already dealt with.', [
                'target' => ['held_deposit', $deposit->id],
                'reason' => 'Its status is '.$deposit->status.'.',
            ]);

            return back()->with('error', 'This deposit has already been dealt with.');
        }

        // Recording that money was judged *not ours to credit* matters as much as
        // recording a credit: it is the branch where a customer was left unpaid on
        // purpose, by name, with a reason.
        $this->audit->recordQuietly('success', 'transaction_review', 'held_deposit.dismiss', 'Dismissed a held deposit without crediting anyone.', [
            'target' => ['held_deposit', $deposit->id],
            'reason' => $validated['note'],
        ]);

        return back()->with('success', 'Marked as not ours to credit. The record is kept.');
    }
}
