<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FlutterwaveService;
use App\Services\WalletFundingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "I sent money and it did not reflect" has several different causes that look
 * identical from the customer's side: the transfer never left their bank, it
 * landed under an account number this site stopped advertising, it arrived short
 * of the amount requested, or the webhook that would have told us about it never
 * reached the server. This asks the ledger and Flutterwave the same questions in
 * order and changes nothing, so the answer says which of those it actually is.
 */
class AuditWalletDeposits extends Command
{
    protected $signature = 'deposits:audit
        {--days=30 : How far back to look}
        {--references=10 : How many outstanding requests to ask Flutterwave about}';

    protected $description = 'Trace wallet transfers the site was told about but never banked (read-only)';

    private int $apiCalls = 0;

    public function handle(FlutterwaveService $flutterwave, WalletFundingService $funding): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->line('');
        $this->line('Wallet deposits audit, looking back '.$days.' day(s). Nothing here is written or changed.');

        $this->ledgerShape($since);
        $this->stillWaiting($since);
        $this->writtenOff($since);

        if (!$flutterwave->configured()) {
            $this->warn('Flutterwave is not configured on this machine, so the money itself cannot be asked about.');

            return self::SUCCESS;
        }

        $this->askFlutterwave($since, $flutterwave, $funding, (int) $this->option('references'));
        $this->logEvidence($days);

        $this->line('');
        $this->info('Flutterwave was asked '.$this->apiCalls.' time(s).');

        return self::SUCCESS;
    }

    private function ledgerShape($since): void
    {
        $rows = DB::table('wallet_transactions')
            ->select('status', 'channel')
            ->selectRaw('count(*) as rows_stored, coalesce(sum(amount),0) as kobo')
            ->where('type', 'credit')
            ->where('created_at', '>=', $since)
            ->groupBy('status', 'channel')
            ->orderByDesc('rows_stored')
            ->get();

        $this->line("\n<comment>Credits recorded on this site</comment>");

        if ($rows->isEmpty()) {
            $this->line('  none in this period.');

            return;
        }

        $this->table(
            ['status', 'channel', 'rows', 'amount'],
            $rows->map(fn ($row): array => [
                $row->status,
                $row->channel,
                (string) $row->rows_stored,
                'N'.number_format((int) $row->kobo / 100, 2),
            ])->all()
        );
    }

    private function stillWaiting($since): void
    {
        $rows = WalletTransaction::query()
            ->where('type', 'credit')
            ->where('status', 'pending')
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $this->line("\n<comment>Still advertised as waiting</comment>");

        if ($rows->isEmpty()) {
            $this->line('  nothing pending.');

            return;
        }

        $this->table(
            ['wallet', 'reference', 'amount', 'channel', 'waiting', 'account told to customer'],
            $rows->map(fn (WalletTransaction $row): array => [
                (string) $row->wallet_id,
                (string) $row->reference,
                'N'.number_format((int) $row->amount / 100, 2),
                (string) $row->channel,
                $row->created_at?->diffForHumans() ?? '-',
                $this->accountOf($row),
            ])->all()
        );
    }

    private function writtenOff($since): void
    {
        $rows = WalletTransaction::query()
            ->where('type', 'credit')
            ->where('status', 'failed')
            ->where('channel', 'like', 'flutterwave%')
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        $this->line("\n<comment>Written off as never arriving</comment>");

        if ($rows->isEmpty()) {
            $this->line('  none.');

            return;
        }

        $this->table(
            ['wallet', 'reference', 'amount', 'closed', 'account told to customer'],
            $rows->map(fn (WalletTransaction $row): array => [
                (string) $row->wallet_id,
                (string) $row->reference,
                'N'.number_format((int) $row->amount / 100, 2),
                $row->updated_at?->diffForHumans() ?? '-',
                $this->accountOf($row),
            ])->all()
        );

        $this->line('  A customer whose request appears here sent money that this site gave up on. The next');
        $this->line('  section says whether Flutterwave ever received it.');
    }

    private function askFlutterwave($since, FlutterwaveService $flutterwave, WalletFundingService $funding, int $limit): void
    {
        $rows = WalletTransaction::query()
            ->where('type', 'credit')
            ->whereIn('status', ['pending', 'failed'])
            ->where('channel', 'like', 'flutterwave%')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        $this->line("\n<comment>What Flutterwave says about those same references</comment>");

        if ($rows->isEmpty()) {
            $this->line('  nothing to ask about.');

            return;
        }

        $findings = [];

        foreach ($rows as $row) {
            $this->apiCalls++;
            $list = $flutterwave->findTransactions(['tx_ref' => (string) $row->reference, 'count' => 20]);

            if (!$list->successful()) {
                $findings[] = [
                    (string) $row->wallet_id,
                    (string) $row->reference,
                    'refused (HTTP '.($list->status()).')',
                    '-',
                    '-',
                ];

                continue;
            }

            $charges = (array) $list->json('data', []);
            if ($charges === []) {
                $findings[] = [
                    (string) $row->wallet_id,
                    (string) $row->reference,
                    'no transfer ever reached that reference',
                    '-',
                    'customer has not actually paid, or paid a different number',
                ];

                continue;
            }

            foreach ($charges as $charge) {
                $account = $funding->chargeAccountNumber((array) $charge);
                $findings[] = [
                    (string) $row->wallet_id,
                    (string) $row->reference,
                    $this->text($charge['status'] ?? null),
                    'N'.number_format((float) ($charge['charged_amount'] ?? $charge['amount'] ?? 0), 2),
                    $this->ownership($row, $account),
                ];
            }
        }

        $this->table(['wallet', 'reference', 'flutterwave says', 'amount', 'who can claim it'], $findings);
    }

    /**
     * Whether the money found at Flutterwave can still be traced back to somebody
     * this site knows, which is the difference between a deposit to re-run and a
     * deposit that has to be refunded by hand.
     */
    private function ownership(WalletTransaction $row, string $account): string
    {
        if ($account === '') {
            return 'arrived without an account number on it';
        }

        $holders = User::query()
            ->where('virtual_account_number', $account)
            ->orWhere('virtual_account_metadata->temporary_virtual_account->account_number', $account)
            ->orWhere('virtual_account_metadata', 'like', '%"'.$account.'"%')
            ->exists();

        if ($holders) {
            return 'account still held by a customer';
        }

        $namedHere = WalletTransaction::query()
            ->where('meta->virtual_account_number', $account)
            ->whereKeyNot($row->id)
            ->exists();

        return $namedHere
            ? 'account only on record in an older request'
            : 'NOBODY HOLDS THIS ACCOUNT NUMBER';
    }

    private function logEvidence(int $days): void
    {
        $markers = [
            'webhook: transfer we could not attribute' => 'Flutterwave virtual account transfer user not found.',
            'webhook: signature refused' => 'Flutterwave webhook signature mismatch.',
            'webhook: hash not configured' => 'Flutterwave webhook rejected. Secret hash is not configured.',
            'deposit poll: money found without a webhook' => 'Wallet deposit found without a webhook.',
            'deposit poll: short transfer held back' => 'Flutterwave reported less than the amount this deposit asked for.',
            'deposit poll: could not reach flutterwave' => 'Wallet deposit check could not ask Flutterwave',
            'deposit poll: request written off' => 'Abandoned wallet funding requests were closed out.',
        ];

        $files = glob(storage_path('logs/laravel-*.log')) ?: [];
        $cutoff = time() - ($days * 86400);
        $counts = array_fill_keys(array_keys($markers), 0);

        // Newest first, and only the handful a day of traffic can realistically
        // fill, so reading the log never turns a diagnostic into a stall.
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) - (int) filemtime($a));
        $files = array_slice(array_values(array_filter(
            $files,
            static fn (string $file): bool => filemtime($file) >= $cutoff
        )), 0, 3);

        $scanned = 0;

        foreach ($files as $file) {
            $scanned++;
            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                foreach ($markers as $label => $needle) {
                    if (str_contains($line, $needle)) {
                        $counts[$label]++;
                    }
                }
            }

            fclose($handle);
        }

        $this->line("\n<comment>What the log says (last ".$days.' day(s), '.$scanned." file(s))</comment>");

        foreach ($counts as $label => $count) {
            $this->line('  '.str_pad($label, 46, ' ').': '.$count);
        }

        if (($counts['webhook: transfer we could not attribute'] ?? 0) > 0) {
            $this->line('  Money reached Flutterwave and the webhook did report it, but the account number it');
            $this->line('  arrived in no longer belongs to any customer record. That is the replaced-account case.');
        }

        if ($counts['webhook: signature refused'] > 0 || $counts['webhook: hash not configured'] > 0) {
            $this->line('  The webhook is arriving but is being turned away, so nothing it reports is banked.');
        }

        if ($counts['deposit poll: money found without a webhook'] === 0
            && $counts['webhook: transfer we could not attribute'] === 0) {
            $this->line('  Neither the webhook nor the deposit poll has produced money in this period: check the');
            $this->line('  payment key on this machine matches the Flutterwave account that issued these numbers.');
        }
    }

    private function accountOf(WalletTransaction $row): string
    {
        return (string) (($row->meta ?? [])['virtual_account_number'] ?? '-');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
