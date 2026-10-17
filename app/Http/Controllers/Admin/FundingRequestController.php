<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A funding request that an admin has already settled by hand - a credit given
 * somewhere else, a refund, or the customer's own confirmation that they were
 * paid - stays open in the ledger, and the deposit sweep asks the gateway about
 * every still-open one. If that transfer later reports as successful, the ordinary
 * funding path would pay the customer a second time, because a manual credit carries
 * no charge identity for the duplicate guard to see.
 *
 * This screen records the settlement against the request itself. It moves no money:
 * it only tells the funding path that this money has already been dealt with, so a
 * later arrival is held for a human instead of credited.
 */
class FundingRequestController extends Controller
{
    public function markSettled(Request $request, WalletTransaction $transaction)
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $this->record($transaction, 'settled', $validated['note'], (int) $request->user()->id);

        return back()->with(
            'success',
            'Funding request #'.$transaction->id.' marked as already settled. Nothing was credited or debited.'
        );
    }

    public function unmarkSettled(Request $request, WalletTransaction $transaction)
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $this->record($transaction, 'reopened', $validated['note'], (int) $request->user()->id);

        return back()->with(
            'success',
            'Funding request #'.$transaction->id.' is open again. A later arrival will be credited normally.'
        );
    }

    /**
     * Write the marker on the request row, under a row lock and in one statement, so
     * two admins cannot overwrite each other's history and a concurrent credit to the
     * same wallet cannot read a half-written marker.
     */
    private function record(WalletTransaction $transaction, string $action, string $note, int $adminId): void
    {
        DB::transaction(function () use ($transaction, $action, $note, $adminId): void {
            $locked = WalletTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== 'credit'
                || !str_starts_with((string) $locked->channel, 'flutterwave')
                || !in_array($locked->status, ['pending', 'failed'], true)) {
                abort(422, 'Only an open Flutterwave funding request can be marked. This row is not one.');
            }

            $meta = (array) ($locked->meta ?? []);
            $history = (array) ($meta['settlement_history'] ?? []);
            $history[] = [
                'action' => $action,
                'by' => $adminId,
                'note' => $note,
                'at' => now()->toIso8601String(),
            ];

            $meta['settlement_history'] = array_slice($history, -30);
            $meta['manually_settled'] = $action === 'settled';
            $meta['manually_settled_note'] = $action === 'settled' ? $note : null;
            $meta['manually_settled_by_user_id'] = $action === 'settled' ? $adminId : null;
            $meta['manually_settled_at'] = $action === 'settled' ? now()->toIso8601String() : null;

            $locked->forceFill(['meta' => $meta])->save();
        });
    }
}
