<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Reads the activity trail. It never writes to it.
 *
 * The page is paginated server-side with a bounded page size on purpose: an
 * audit table is the largest table an admin screen can point at, and a mobile
 * browser that asked for all of it would die before the owner saw anything.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'admin' => $request->integer('admin') > 0 ? $request->integer('admin') : null,
            'category' => in_array((string) $request->query('category'), AdminActivityLog::CATEGORIES, true)
                ? (string) $request->query('category')
                : '',
            'result' => in_array((string) $request->query('result'), AdminActivityLog::RESULTS, true)
                ? (string) $request->query('result')
                : '',
            'from' => $this->date((string) $request->query('from', '')),
            'to' => $this->date((string) $request->query('to', '')),
        ];

        $query = AdminActivityLog::query()->orderByDesc('id');

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($like): void {
                $q->where('action', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('target_id', 'like', $like)
                    ->orWhere('correlation_id', 'like', $like);
            });
        }

        if ($filters['admin'] !== null) {
            $query->where('admin_user_id', $filters['admin']);
        }

        if ($filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if ($filters['result'] !== '') {
            $query->where('result', $filters['result']);
        }

        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        // Counted over the same filter, in one grouped statement, and deliberately
        // not over the page: "3 refusals on page 2 of 40" would be read as the
        // whole story. reorder() keeps the list's ORDER BY out of the GROUP BY,
        // which strict MySQL would otherwise reject.
        $counts = (clone $query)
            ->reorder()
            ->selectRaw('result, count(*) as total_rows')
            ->groupBy('result')
            ->pluck('total_rows', 'result')
            ->all();

        $logs = $query->paginate($perPage)->appends($request->query());

        return view('admin.activity-log', [
            'logs' => $logs,
            'filters' => $filters,
            'perPage' => $perPage,
            'administrators' => User::query()->where('is_admin', true)->orderBy('id')->get(['id', 'name', 'email']),
            'focusAdmin' => $filters['admin'] !== null
                ? User::query()->whereKey($filters['admin'])->first(['id', 'name', 'email', 'is_admin', 'created_at', 'last_login_at'])
                : null,
            'counts' => $counts,
        ]);
    }

    private function date(string $value): string
    {
        $stamp = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $stamp !== false && $stamp->format('Y-m-d') === $value ? $value : '';
    }
}
