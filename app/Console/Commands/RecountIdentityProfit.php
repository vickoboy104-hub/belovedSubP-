<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ManualFulfilmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The identity orders that were booked at nothing.
 *
 * Every identity path used to record only the markup typed on top of a price as
 * its profit, so the margin built into the price itself never reached a total —
 * and a verification read back out of a record this site already holds, which
 * costs no provider call at all, was booked as earning nothing. Purchases made
 * from now on are recorded correctly; this walks the orders already in the table
 * and works out what each one actually kept, from the order and the owner's own
 * rate sheet and nothing else.
 *
 * It changes nothing until --apply is passed, so the answer to a complaint about
 * a number can be read off the screen first.
 */
class RecountIdentityProfit extends Command
{
    protected $signature = 'identity:recount-profit
        {--apply : Write the recalculated profit; without it nothing is saved}
        {--days=0 : Only look at orders from the last N days (0 is every order)}';

    protected $description = 'Recount what the identity business kept on orders already stored (read-only unless --apply)';

    /** @var array<string, array{rows: int, charged: int, was: int, now: int}> */
    private array $groups = [];

    private int $alreadyRight = 0;

    private int $noRateOnFile = 0;

    public function handle(ManualFulfilmentService $manualServices): int
    {
        $days = max(0, (int) $this->option('days'));
        $apply = (bool) $this->option('apply');

        $this->line('');
        $this->line($apply
            ? 'Recounting identity profit and WRITING the new figures.'
            : 'Recounting identity profit. Nothing will be written unless --apply is passed.');

        if ($days > 0) {
            $this->line('Looking back '.$days.' day(s) of orders.');
        }

        $query = Order::query()
            ->where('status', 'success')
            ->where(function ($scoped) {
                $scoped->whereIn('meta->type', ['nin', 'nin_validation', 'bvn', 'manual_service'])
                    ->orWhere('provider', 'manual');
            })
            ->when($days > 0, fn ($builder) => $builder->where('created_at', '>=', now()->subDays($days)));

        $query->select(['id', 'amount', 'profit', 'provider', 'meta'])
            ->chunkById(200, function ($orders) use ($manualServices, $apply) {
                foreach ($orders as $order) {
                    $this->recount($order, $manualServices, $apply);
                }
            }, 'id', 'id');

        $this->report($apply);

        return self::SUCCESS;
    }

    private function recount(Order $order, ManualFulfilmentService $manualServices, bool $apply): void
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $costNaira = $this->costNaira($meta, $manualServices);

        if ($costNaira === null) {
            $this->noRateOnFile++;

            return;
        }

        $charged = (int) $order->amount;
        $kept = max(0, $charged - (int) round($costNaira * 100));
        $group = $this->category($meta);

        $this->group($group, $charged, (int) $order->profit, $kept);

        if ($kept === (int) $order->profit) {
            $this->alreadyRight++;

            return;
        }

        if (! $apply) {
            return;
        }

        // updated_at is left alone: this repairs a reported figure, it is not an
        // event in the customer's order, and moving it would reorder history.
        DB::table('orders')->where('id', $order->id)->update(['profit' => $kept]);
    }

    /**
     * What the provider was paid for this order, from the record the order keeps.
     *
     * Null means the rate sheet holds nothing for that job, and then no profit is
     * claimed rather than guessing a margin — the stored figure is left alone.
     */
    private function costNaira(array $meta, ManualFulfilmentService $manualServices): ?float
    {
        $stored = $meta['provider_cost_naira'] ?? null;
        if ($stored !== null && is_numeric($stored)) {
            return (float) $stored;
        }

        $type = (string) ($meta['type'] ?? '');
        $service = (string) ($meta['service_type'] ?? '');

        if ($type === 'nin' && $service === 'verify') {
            // A repeat answered from the record held here asked the provider
            // for nothing, so the whole charge was kept.
            if (! empty($meta['cache_hit'])) {
                return 0.0;
            }

            return identity_cost($this->ninVerifyKey((string) ($meta['verification_type'] ?? '')));
        }

        if ($type === 'nin' && $service === 'print') {
            // A slip drawn from a verified record was made from artwork this
            // site ships, so no provider was ever asked for it.
            if (! empty($meta['source_verification_order_id'])
                || ! empty($meta['provider_response']['data']['local_generated'])) {
                return 0.0;
            }

            return identity_cost($this->ninSlipKey((string) ($meta['slip_type'] ?? '')));
        }

        if ($type === 'nin_validation') {
            return identity_cost(($meta['validation_type'] ?? '') === 'update_record'
                ? 'price_nin_validation_update_record'
                : 'price_nin_validation_no_record');
        }

        if ($type === 'bvn' && $service === 'verify') {
            return identity_cost('price_bvn_verify');
        }

        if ($type === 'bvn' && $service === 'retrieve') {
            return identity_cost(($meta['retrieve_type'] ?? '') === 'bms'
                ? 'price_bvn_retrieve_bms'
                : 'price_bvn_retrieve_phone');
        }

        if ($type === 'manual_service' || ($meta['manual_queue'] ?? null) === true) {
            $submitted = is_array($meta['submitted'] ?? null) ? $meta['submitted'] : [];

            return $manualServices->providerCost((string) ($meta['manual_service'] ?? ''), $submitted);
        }

        return null;
    }

    /**
     * Which way a stored verification was asked for.
     *
     * The wired route saves the search type bare ("phone") and the queue saves
     * the form's own option ("by_phone"); both name the same rate.
     */
    private function ninVerifyKey(string $verificationType): string
    {
        return match (str_replace('by_', '', $verificationType)) {
            'phone' => 'price_nin_verify_by_phone',
            'demo' => 'price_nin_verify_by_demo',
            default => 'price_nin_verify',
        };
    }

    private function ninSlipKey(string $slipType): string
    {
        return match ($slipType) {
            'standard_slip' => 'price_nin_slip_standard',
            'premium_slip' => 'price_nin_slip_premium',
            'vnin_slip' => 'price_nin_slip_vnin',
            default => 'price_nin_slip_long',
        };
    }

    private function category(array $meta): string
    {
        $type = (string) ($meta['type'] ?? '');
        $service = (string) ($meta['service_type'] ?? '');

        if ($type === 'nin' && $service === 'verify') {
            return ! empty($meta['cache_hit']) ? 'NIN verification, read from a stored record' : 'NIN verification';
        }

        if ($type === 'nin' && $service === 'print') {
            return 'NIN slip printing';
        }

        if ($type === 'nin_validation') {
            return 'NIN validation';
        }

        if ($type === 'bvn') {
            return $service === 'retrieve' ? 'BVN retrieval' : 'BVN verification';
        }

        if ($type === 'manual_service' || ($meta['manual_queue'] ?? null) === true) {
            return 'Hand-worked identity request';
        }

        return 'Other identity order';
    }

    /** Book one order against its category, keeping the money in kobo. */
    private function group(string $category, int $charged, int $was, int $now): void
    {
        if (! isset($this->groups[$category])) {
            $this->groups[$category] = ['rows' => 0, 'charged' => 0, 'was' => 0, 'now' => 0];
        }

        $this->groups[$category]['rows']++;
        $this->groups[$category]['charged'] += $charged;
        $this->groups[$category]['was'] += $was;
        $this->groups[$category]['now'] += $now;
    }

    private function report(bool $apply): void
    {
        $this->line("\n<comment>Identity orders looked at</comment>");

        if ($this->groups === []) {
            $this->line('   No identity order in range carries a rate this command can read.');

            return;
        }

        $rows = [];
        foreach ($this->groups as $category => $group) {
            $rows[] = [
                $category,
                number_format($group['rows']),
                'N'.number_format($group['charged'] / 100, 2),
                'N'.number_format($group['was'] / 100, 2),
                'N'.number_format($group['now'] / 100, 2),
            ];
        }

        $this->table(['What was sold', 'Orders', 'Charged', 'Profit stored', 'Profit kept'], $rows);

        $this->line('');
        $this->line('Orders already carrying the right figure: '.$this->alreadyRight);
        $this->line('Orders with no provider rate on file, left alone: '.$this->noRateOnFile);

        if ($apply) {
            $this->info('Profit figures have been written.');

            return;
        }

        $this->warn('Nothing was written. Run the same command with --apply to save these figures.');
    }
}
