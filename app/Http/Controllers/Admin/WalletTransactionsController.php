<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\AdminActivityRecorder;
use Illuminate\Http\Request;

class WalletTransactionsController extends Controller
{
    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $query = WalletTransaction::with(['wallet.user'])->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('channel', 'like', "%{$search}%");

                $q->orWhereHas('wallet.user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->paginate(20)->appends($request->query());

        // Reading the ledger is itself an event worth recording: the question
        // "who looked at this customer's money" has no other answer. What was
        // typed stays out of the trail - a search box can hold anything, including
        // someone's NIN - so the record names the filters and how many rows came
        // back, not the query.
        if ($request->query() !== []) {
            $this->audit->recordQuietly('success', 'transaction_review', 'wallet.transactions_searched', 'Searched the wallet transaction ledger.', [
                'changes' => [
                    'filters' => array_keys($request->query()),
                    'results' => $transactions->total(),
                ],
                'throttle_seconds' => 60,
            ]);
        }

        return view('admin.wallet-transactions', compact('transactions'));
    }
}
