<?php

namespace App\Console\Commands;

use App\Services\WalletDepositSync;
use Illuminate\Console\Command;

/**
 * A bank transfer only becomes a wallet balance when somebody asks Flutterwave
 * about it. Customers ask by opening their transactions page, which silently
 * means a transfer that lands after they stop looking is never noticed. This
 * asks on their behalf.
 */
class SyncWalletDeposits extends Command
{
    protected $signature = 'wallet:sync-deposits {--limit=10 : Wallets to ask Flutterwave about on this run}';

    protected $description = 'Credit wallet transfers that arrived without the site being told about them';

    public function handle(WalletDepositSync $deposits): int
    {
        $summary = $deposits->sweep((int) $this->option('limit'));

        $this->info(sprintf(
            'Checked %d wallet(s); credited %d deposit(s) totalling %s.',
            $summary['checked'],
            $summary['credited'],
            number_format($summary['credited_kobo'] / 100, 2)
        ));

        return self::SUCCESS;
    }
}
