<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner's view of who holds administrator access.
 *
 * Everything here is a column that already exists or a count taken from the
 * activity trail this feature started writing. Where history was never recorded
 * the page says so instead of guessing: a login that predates the trail shows
 * "Not recorded", and the activity count begins at the day the trail began, not
 * at the day the account was created.
 */
class AdministratorsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $scope = in_array((string) $request->query('scope'), ['admins', 'members', 'all'], true)
            ? (string) $request->query('scope')
            : 'admins';

        $ownerIds = array_map('intval', (array) config('audit.owner_user_ids'));

        $query = User::query()->orderBy('id');

        if ($scope === 'admins') {
            $query->where('is_admin', true);
        } elseif ($scope === 'members') {
            $query->where('is_admin', false);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                // A number that names a real account means that account alone; the
                // id is what the audit trail itself records.
                if (ctype_digit($search) && (int) $search > 0 && User::query()->whereKey((int) $search)->exists()) {
                    $q->where('id', (int) $search);

                    return;
                }

                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(10, min(100, $perPage));

        $users = $query->paginate($perPage)->appends($request->query());

        // One grouped query for the whole page rather than one per row, so the
        // screen cannot be turned into a database storm by widening the page size.
        $ids = $users->pluck('id')->all();

        $stats = AdminActivityLog::query()
            ->whereIn('admin_user_id', $ids)
            ->groupBy('admin_user_id')
            ->selectRaw('admin_user_id, count(*) as activity_count, max(created_at) as last_activity_at')
            ->get()
            ->keyBy('admin_user_id');

        // Which permissions, not just whether the flag is on. One grouped query for
        // the page, so the answer is on this screen and not only on the Master's.
        $granted = AdminPermissionGrant::query()
            ->whereIn('user_id', $ids)
            ->orderBy('permission')
            ->get(['user_id', 'permission'])
            ->groupBy('user_id')
            ->map(fn ($rows): array => $rows->pluck('permission')->all());

        return view('admin.administrators', [
            'users' => $users,
            'stats' => $stats,
            'granted' => $granted,
            'masterIds' => AdminPermissions::masterUserIds(),
            'permissionTotal' => count(AdminPermissions::slugs()),
            'search' => $search,
            'scope' => $scope,
            'perPage' => $perPage,
            'ownerIds' => $ownerIds,
            'ownerConfigured' => $ownerIds !== [],
            'adminCount' => User::query()->where('is_admin', true)->count(),
            'trailStart' => AdminActivityLog::query()->min('created_at'),
        ]);
    }
}
