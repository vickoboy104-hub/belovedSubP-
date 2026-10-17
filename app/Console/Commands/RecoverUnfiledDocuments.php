<?php

namespace App\Console\Commands;

use App\Models\DocumentFiling;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AdminSystemAlertNotification;
use App\Services\ManualDocumentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Finish filing the paper a paid request already bought.
 *
 * A customer's documents are written to disk before the wallet is touched, the
 * order and its holding record are committed together, and the move into the
 * order's own folder happens after that. A process that dies in that last stretch
 * leaves the worst shape on the money path: the customer has paid, the order says
 * pending, and the only place that knew where the paper was sitting was the memory
 * of a request that no longer exists.
 *
 * This walks the holding records that are still open and does the move the
 * interrupted request never got to. It never touches a wallet: a document that
 * cannot be found is escalated to an agent rather than quietly turned into a
 * refund, because the customer paid for the job, not for their money back.
 *
 * It is safe to run as often as the scheduler likes. Each record is shut as soon
 * as its paper lands, a record is only ever worked under its own row lock, and a
 * second pass over finished work files nothing and books nothing.
 */
class RecoverUnfiledDocuments extends Command
{
    /**
     * How many passes a document gets before it is called lost. A disk that was
     * merely busy recovers on the next run; a folder that has really gone does not
     * get to sit in front of an agent forever.
     */
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'manual:recover-documents
        {--limit=50 : How many open holdings to work on this run}
        {--order= : Work one order only, for checking a complaint}';

    protected $description = 'File the documents a paid identity request held but never reached, and name the ones that need a person';

    private int $filed = 0;

    private int $waiting = 0;

    private int $lost = 0;

    private int $alreadyDone = 0;

    public function handle(ManualDocumentStore $store): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $orderId = $this->option('order') === null ? null : (int) $this->option('order');

        $ids = DocumentFiling::query()
            ->where('state', DocumentFiling::AWAITING)
            ->when($orderId !== null, fn ($query) => $query->where('order_id', $orderId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Nothing is waiting to be filed.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            $this->recover((int) $id, $store);
        }

        $this->table(
            ['Filed now', 'Still waiting', 'Needs a person', 'Nothing to do'],
            [[
                $this->filed,
                $this->waiting,
                $this->lost,
                $this->alreadyDone,
            ]],
        );

        return self::SUCCESS;
    }

    private function recover(int $filingId, ManualDocumentStore $store): void
    {
        try {
            DB::transaction(function () use ($filingId, $store): void {
                $filing = DocumentFiling::query()
                    ->where('id', $filingId)
                    ->lockForUpdate()
                    ->first();

                // Another pass, or another worker on the same box, already shut
                // this one. Reading it under the lock is what makes a repeat run
                // a no-op instead of a second attempt at the same move.
                if ($filing === null || $filing->state !== DocumentFiling::AWAITING) {
                    $this->alreadyDone++;

                    return;
                }

                $order = Order::query()->find($filing->order_id);

                // A request that was closed and paid back must not be given paper
                // after the fact: its money has already been settled one way.
                if ($order === null || $order->status === 'failed') {
                    $filing->state = DocumentFiling::ABANDONED;
                    $filing->last_error = 'The request was closed before its documents were filed.';
                    $filing->save();

                    $this->alreadyDone++;

                    return;
                }

                $this->fileThePaper($filing, $order, $store);
            });
        } catch (Throwable $e) {
            $this->waiting++;

            $this->error('Holding #'.$filingId.' could not be worked: '.$e->getMessage());
        }
    }

    private function fileThePaper(DocumentFiling $filing, Order $order, ManualDocumentStore $store): void
    {
        $uploads = (array) ($order->meta['uploads'] ?? []);
        $filedNow = [];
        $stillToFind = [];
        $missing = [];

        foreach ((array) $filing->manifest as $document) {
            $name = (string) ($document['name'] ?? 'document');

            // Already on the order - a pass that filed some documents and then
            // died before it could shut the record.
            if (isset($uploads[$name])) {
                continue;
            }

            $held = (string) ($document['path'] ?? '');

            if ($store->isHeld($held)) {
                $path = $store->publish($filing->token, $held, (int) $order->id);
            } else {
                $path = $store->alreadyFiled((int) $order->id, $held);
            }

            if ($path === null) {
                $missing[] = $name;
                $stillToFind[] = $document;

                continue;
            }

            $uploads[$name] = [
                'path' => $path,
                'name' => (string) ($document['original'] ?? $name),
                'mime' => (string) ($document['mime'] ?? ''),
            ];

            $filedNow[] = $name;
        }

        if ($filedNow !== []) {
            $order->meta = array_merge((array) ($order->meta ?? []), ['uploads' => $uploads]);
            $order->save();
        }

        $filing->attempts = (int) $filing->attempts + 1;
        $filing->manifest = array_values($stillToFind);

        if ($missing === []) {
            $filing->state = DocumentFiling::FILED;
            $filing->last_error = null;
            $filing->save();

            // The folder is empty once its paper has moved, and a paid order no
            // longer needs it, so this is the first moment it is safe to remove.
            $store->discard(token: $filing->token);

            $this->filed += count($filedNow);

            $this->line('Order #'.$order->id.' filed '.implode(', ', $filedNow));

            return;
        }

        $filing->last_error = 'Not received: '.implode(', ', $missing);

        if ((int) $filing->attempts >= self::MAX_ATTEMPTS) {
            $filing->state = DocumentFiling::LOST;
            $filing->save();

            $this->lost++;

            $this->alertAdmins($order, $missing);
            $this->warn('Order #'.$order->id.' is short of '.implode(', ', $missing).'; an agent has to act.');

            return;
        }

        $filing->save();

        $this->waiting++;

        $this->line('Order #'.$order->id.' still owes '.implode(', ', $missing).' (pass '.$filing->attempts.')');
    }

    /**
     * The one case a person has to act on: the money is in, the paper is gone, and
     * the only remaining decision is whether to chase the customer for the document
     * or hand the money back. That is not a call a scheduled job gets to make.
     *
     * @param  list<string>  $missing
     */
    private function alertAdmins(Order $order, array $missing): void
    {
        try {
            $admins = User::query()->where('is_admin', true)->get();

            if ($admins->isEmpty()) {
                return;
            }

            $meta = (array) ($order->meta ?? []);
            $title = (string) ($meta['manual_service_title'] ?? 'Identity request');

            Notification::send($admins, new AdminSystemAlertNotification(
                title: 'ACTION NEEDED: paid request is missing its documents',
                message: 'Order #'.$order->id.' ('.$title.') was paid for, but '.implode(', ', $missing)
                    .' could not be attached to it. The customer has not been refunded and the request is still open: '
                    .'contact the customer for the document, or reject the request to return the money.',
                severity: 'critical',
                url: route('admin.manual-orders.show', $order->id),
                payload: [
                    'type' => 'manual_order_documents_lost',
                    'order_id' => $order->id,
                ],
            ));
        } catch (Throwable $e) {
            $this->error('Could not alert admins about order #'.$order->id.': '.$e->getMessage());
        }
    }
}
