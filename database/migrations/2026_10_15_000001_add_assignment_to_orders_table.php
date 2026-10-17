<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is working a paid manual request, as a real column instead of a note in
 * the order's JSON.
 *
 * A claim has to be something two admins can fight over safely, and a value
 * buried inside a JSON blob cannot be compared inside an UPDATE: the only
 * question that matters - "was this still unclaimed the instant I took it?" -
 * has to be answered by the database in the same statement that writes the
 * answer. It also carries the customer-facing "In Progress" label, so it is read
 * on every order page rather than only by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'assigned_to')) {
            Schema::table('orders', function (Blueprint $table): void {
                // No foreign key: users is deleted restrict elsewhere, and an
                // account removed after working a request must not take the
                // audit trail with it.
                $table->unsignedBigInteger('assigned_to')->nullable()->after('status');
            });
        }

        if (!Schema::hasColumn('orders', 'assigned_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasIndex('orders', 'orders_assigned_to_status_index')) {
                $table->index(['assigned_to', 'status'], 'orders_assigned_to_status_index');
            }
        });

        $this->backfill();
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasIndex('orders', 'orders_assigned_to_status_index')) {
                $table->dropIndex('orders_assigned_to_status_index');
            }
        });

        foreach (['assigned_at', 'assigned_to'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Carry over the requests an admin had already marked as started, so nobody
     * loses a job they were in the middle of when this ships.
     *
     * The old marker lived in JSON, and comparing a value inside JSON is not
     * answered the same way by both database engines, so the rows are read and
     * decided in PHP instead.
     */
    private function backfill(): void
    {
        DB::table('orders')
            ->whereNull('assigned_to')
            ->where('status', 'pending')
            ->whereNotNull('meta')
            ->select(['id', 'meta'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);
                    if (!is_array($meta) || ($meta['work_state'] ?? null) !== 'in_progress') {
                        continue;
                    }

                    $adminId = isset($meta['work_state_by']) ? (int) $meta['work_state_by'] : 0;
                    if ($adminId <= 0) {
                        continue;
                    }

                    $startedAt = null;
                    if (!empty($meta['work_state_at'])) {
                        try {
                            $startedAt = \Illuminate\Support\Carbon::parse((string) $meta['work_state_at']);
                        } catch (\Throwable $e) {
                            $startedAt = null;
                        }
                    }

                    DB::table('orders')->where('id', $row->id)->whereNull('assigned_to')->update([
                        'assigned_to' => $adminId,
                        'assigned_at' => ($startedAt ?? now()),
                    ]);
                }
            });
    }
};
