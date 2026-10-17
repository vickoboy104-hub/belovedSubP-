<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlutterwaveHeldDeposit;
use App\Models\WalletSettlementLink;
use App\Models\WalletTransaction;
use App\Services\AdminActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that may connect a gateway charge to money the support desk already
 * paid by hand.
 *
 * Both manual credit channels are covered - the fund-wallet tool and the balance
 * adjustment tool - and neither is ever presumed to correspond to a gateway
 * transaction on its own. A credit that names no charge is exactly what it looks
 * like: help the desk gave, which must not hold the customer's next genuine
 * transfer. So the connection is made here, by an admin who has read both records,
 * keyed on the identifier Flutterwave gives one individual charge, and signed with a
 * reason. Nothing infers it, and nothing deletes it: a wrong declaration is retracted
 * with its own note, and both acts stay in the audit trail.
 *
 * No money moves through this screen. The only thing it changes is what the funding
 * path does when the charge named here is reported again.
 */
class SettlementLinkController extends Controller
{
    /**
     * The two channels through which a human, not a gateway, has ever added money to
     * a customer wallet.
     *
     * @var list<string>
     */
    private const MANUAL_CHANNELS = ['admin_manual_credit', 'admin_wallet_adjustment'];

    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $links = WalletSettlementLink::query()
            ->with(['manualTransaction', 'declaredBy', 'retractedBy'])
            ->when(
                $request->input('status') === 'retracted',
                fn ($query) => $query->where('status', '!=', 'active'),
                fn ($query) => $query->where('status', 'active')
            )
            ->latest('id')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.settlement-links', [
            'links' => $links,
            'heldDeposits' => FlutterwaveHeldDeposit::query()
                ->where('status', 'held')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'manualCredits' => WalletTransaction::query()
                ->with(['wallet.user'])
                ->where('type', 'credit')
                ->whereIn('channel', self::MANUAL_CHANNELS)
                ->latest('id')
                ->limit(50)
                ->get(),
            'activeCount' => WalletSettlementLink::query()->where('status', 'active')->count(),
            'retractedCount' => WalletSettlementLink::query()->where('status', '!=', 'active')->count(),
            'showRetracted' => $request->input('status') === 'retracted',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'held_deposit_id' => ['nullable', 'integer'],
            'charge_id' => ['nullable', 'string', 'max:191'],
            'flw_ref' => ['nullable', 'string', 'max:191'],
            'manual_transaction_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $link = $this->declare($request->user()->id, $validated);
        } catch (ValidationException $e) {
            $this->audit->recordQuietly('failed', 'transaction_review', 'settlement_link.declare', 'Declaration refused.', [
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        return back()->with(
            'success',
            'Charge '.$link->charge_token.' is recorded as already settled by hand (declaration #'.$link->id.
            '). A later report of that exact charge will be held, not credited. Nothing was paid or moved.'
        );
    }

    public function retract(Request $request, WalletSettlementLink $link)
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        if ($link->isRetracted()) {
            $this->audit->recordQuietly('failed', 'transaction_review', 'settlement_link.retract', 'Attempt to retract a declaration that is already retracted.', [
                'target' => ['settlement_link', (int) $link->id],
            ]);

            return back()->with('error', 'That declaration is already retracted.');
        }

        DB::transaction(function () use ($link, $request, $validated): void {
            $locked = WalletSettlementLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => 'retracted',
                'retracted_by_user_id' => (int) $request->user()->id,
                'retracted_at' => now(),
                'retraction_note' => $validated['note'],
            ])->save();

            $this->audit->record('success', 'transaction_review', 'settlement_link.retract', 'Retracted a manual settlement declaration.', [
                'target' => ['settlement_link', (int) $locked->id],
                'reference' => $locked->charge_token,
                'reason' => $validated['note'],
                'changes' => [
                    'before' => ['status' => 'active'],
                    'after' => ['status' => 'retracted'],
                    'manual_transaction_id' => (int) $locked->manual_transaction_id,
                ],
            ]);
        });

        return back()->with('success', 'Declaration retracted. A later report of that charge is processed normally again.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function declare(int $adminId, array $validated): WalletSettlementLink
    {
        $credit = WalletTransaction::query()->find((int) $validated['manual_transaction_id']);

        if (!$credit || $credit->type !== 'credit' || !in_array((string) $credit->channel, self::MANUAL_CHANNELS, true)) {
            throw ValidationException::withMessages([
                'manual_transaction_id' => 'Name a wallet transaction that was credited by hand - the fund-wallet tool or a balance adjustment.',
            ]);
        }

        [$token, $kind, $txRef] = $this->chargeToken($validated);

        if ($token === '') {
            throw ValidationException::withMessages([
                'charge_id' => 'A declaration has to name one charge. Use the charge id or flw_ref from the payment record.',
            ]);
        }

        if (WalletSettlementLink::query()->where('charge_token', $token)->exists()) {
            throw ValidationException::withMessages([
                'charge_id' => 'This charge already has a declaration against it. Retract that one first if it was wrong.',
            ]);
        }

        return DB::transaction(function () use ($credit, $token, $kind, $txRef, $adminId, $validated): WalletSettlementLink {
            $link = WalletSettlementLink::create([
                'charge_token' => $token,
                'charge_token_kind' => $kind,
                'tx_ref' => $txRef !== '' ? $txRef : null,
                'wallet_id' => (int) $credit->wallet_id,
                'manual_transaction_id' => (int) $credit->id,
                'amount_kobo' => (int) $credit->amount,
                'status' => 'active',
                'reason' => $validated['reason'],
                'declared_by_user_id' => $adminId,
                'declared_at' => now(),
            ]);

            // The declaration decides whether a future arrival is paid or held, so
            // the who, when and why commit with it or not at all.
            $this->audit->record('success', 'transaction_review', 'settlement_link.declare', 'Declared a gateway charge already settled by hand.', [
                'target' => ['settlement_link', (int) $link->id],
                'reference' => $token,
                'reason' => $validated['reason'],
                'changes' => [
                    'charge_token_kind' => $kind,
                    'manual_transaction_id' => (int) $credit->id,
                    'manual_channel' => (string) $credit->channel,
                    'amount_kobo' => (int) $credit->amount,
                ],
            ]);

            return $link;
        });
    }

    /**
     * The identifier of one individual charge, from whichever record the admin chose
     * to point at. A held deposit is preferred because its tokens were written by the
     * gateway itself rather than copied from a dashboard.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0:string,1:string,2:string}
     */
    private function chargeToken(array $validated): array
    {
        if (($validated['held_deposit_id'] ?? null) !== null && $validated['held_deposit_id'] !== '') {
            $held = FlutterwaveHeldDeposit::query()->find((int) $validated['held_deposit_id']);

            if (!$held) {
                throw ValidationException::withMessages([
                    'held_deposit_id' => 'That held deposit does not exist.',
                ]);
            }

            $chargeId = trim((string) ($held->flutterwave_charge_id ?? ''));
            $flwRef = trim((string) ($held->flutterwave_id ?? ''));

            return [
                $chargeId !== '' ? $chargeId : $flwRef,
                $chargeId !== '' ? 'charge_id' : 'flw_ref',
                trim((string) ($held->tx_ref ?? '')),
            ];
        }

        $chargeId = trim((string) ($validated['charge_id'] ?? ''));
        $flwRef = trim((string) ($validated['flw_ref'] ?? ''));

        if ($chargeId !== '' && str_starts_with($chargeId, 'FLW_')) {
            throw ValidationException::withMessages([
                'charge_id' => 'That is one of this site\'s own references, not a gateway charge id.',
            ]);
        }

        return [
            $chargeId !== '' ? $chargeId : $flwRef,
            $chargeId !== '' ? 'charge_id' : 'flw_ref',
            '',
        ];
    }
}
