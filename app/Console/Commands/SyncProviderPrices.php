<?php

namespace App\Console\Commands;

use App\Services\ProviderPlanPriceService;
use Illuminate\Console\Command;

class SyncProviderPrices extends Command
{
    protected $signature = 'prices:sync {--limit= : Refresh only the N least recently synced services}';

    protected $description = 'Fetch the provider plan catalogue so website selling prices stay current';

    public function handle(ProviderPlanPriceService $planPrices): int
    {
        $limit = (int) ($this->option('limit') ?? 0);
        $summary = $planPrices->syncProviderPrices($limit);

        $this->info(sprintf(
            'Refreshed %d service(s), %d plan price(s) stored.',
            count($summary['attempted_services']),
            $summary['synced_plans']
        ));

        foreach ($summary['failed_services'] as $slug) {
            $this->warn('Could not refresh: '.$slug);
        }

        return $summary['failed_services'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
