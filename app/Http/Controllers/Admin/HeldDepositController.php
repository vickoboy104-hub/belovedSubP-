<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlutterwaveHeldDeposit;
use App\Models\User;
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
            return back()->with('error', 'This deposit has already been dealt with.');
        }

        $customer = User::query()->findOrFail($validated['user_id']);
        $credited = $funding->creditHeldDepositTo($deposit, $customer, $request->user(), $validated['note']);

        if ($credited <= 0) {
            return back()->with('error', 'Nothing was credited: this charge has already been paid out somewhere.');
        }

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
            return back()->with('error', 'This deposit has already been dealt with.');
        }

        return back()->with('success', 'Marked as not ours to credit. The record is kept.');
    }
}
