<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminActivityRecorder;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The owner's question, on one page: which administrator is allowed to do which
 * thing.
 *
 * Two rules shape everything here, and both exist to keep the answer the owner's.
 *
 * A Master Admin is not something this screen can create. The role comes from the
 * server's configuration, so the reach of the account that controls every other
 * account cannot be widened by a form post, a stolen admin session or a row written
 * into the database. The permission that manages permissions is deliberately absent
 * from the assignable list.
 *
 * An account cannot edit its own access. Not to remove it, and not to add to it.
 * Self-elevation is the obvious attack and self-lockout is the obvious accident, so
 * the acting administrator's own grants are out of reach of their own session, and a
 * second Master Admin named in configuration is the recovery path.
 */
class PermissionsController extends Controller
{
    public function __construct(
        private readonly AdminActivityRecorder $audit,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $administrators = User::query()
            ->where('is_admin', true)
            ->when($search !== '', function ($query) use ($search): void {
                // An id is the one identifier that cannot be re-typed by somebody
                // else, so the search box takes it as its first-class input - and a
                // number that is nobody's id falls back to the name search rather
                // than reporting nothing.
                if (ctype_digit($search) && (int) $search > 0 && User::query()->whereKey((int) $search)->exists()) {
                    $query->where('id', (int) $search);

                    return;
                }

                $query->where('name', 'like', '%'.$search.'%');
            })
            ->with('permissionGrants')
            ->orderBy('id')
            ->paginate(20)
            ->appends($request->query());

        $held = [];
        foreach ($administrators->getCollection() as $admin) {
            $held[(int) $admin->id] = $admin->adminPermissionSlugs();
        }

        return view('admin.permissions', [
            'administrators' => $administrators,
            'held' => $held,
            'groups' => AdminPermissions::grouped(),
            'masterIds' => AdminPermissions::masterUserIds(),
            'search' => $search,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(AdminPermissions::slugs())],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $actor = $request->user();
        $wanted = array_values(array_unique($validated['permissions'] ?? []));

        if ((int) $actor->id === (int) $user->id) {
            $this->audit->recordQuietly('failed', 'auth', 'permissions.self_edit', 'Own permission set touched.', [
                'target' => ['user', (int) $user->id],
                'reason' => 'An account may not edit its own access.',
                'changes' => ['requested' => $wanted],
            ]);

            return back()->with('error', 'An account cannot change its own permissions.');
        }

        if (!$user->is_admin) {
            $this->audit->recordQuietly('failed', 'auth', 'permissions.target_not_admin', 'Grant attempted on an account that is not an administrator.', [
                'target' => ['user', (int) $user->id],
                'reason' => 'The account does not hold the administrator flag.',
                'changes' => ['requested' => $wanted],
            ]);

            return back()->with('error', 'Only an administrator account can hold administrator permissions.');
        }

        if ($user->isMasterAdmin()) {
            $this->audit->recordQuietly('failed', 'auth', 'permissions.master_protected', 'Edit attempted on a Master Admin permission set.', [
                'target' => ['user', (int) $user->id],
                'reason' => 'A Master Admin holds every permission implicitly.',
                'changes' => ['requested' => $wanted],
            ]);

            return back()->with('error', 'A Master Admin holds every permission and their set cannot be edited.');
        }

        [$added, $removed] = DB::transaction(function () use ($user, $wanted, $actor, $validated): array {
            $current = $user->permissionGrants()->pluck('permission')->all();

            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));

            if ($removed !== []) {
                $user->permissionGrants()->whereIn('permission', $removed)->delete();
            }

            foreach ($added as $slug) {
                $user->permissionGrants()->insert([
                    [
                        // insert() writes the statement as given - unlike create() it
                        // never fills the owner key, so a grant written without it
                        // fails against the NOT NULL column.
                        'user_id' => (int) $user->id,
                        'permission' => $slug,
                        'granted_by_user_id' => (int) $actor->id,
                        'note' => $validated['reason'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }

            // The diff and the rows it describes commit together, so a grant that
            // was rolled back never has a signed history, and a signed history never
            // describes a grant that did not persist.
            $this->audit->record('success', 'auth', 'permissions.updated', 'Permission set updated for '.$user->name.' (User ID '.$user->id.').', [
                'target' => ['user', (int) $user->id],
                'reason' => $validated['reason'],
                'changes' => [
                    'before' => $current,
                    'after' => $wanted,
                    'added' => $added,
                    'removed' => $removed,
                ],
            ]);

            return [$added, $removed];
        });

        $summary = 'Updated '.$user->name.' (User ID '.$user->id.').';

        if ($added !== []) {
            $summary .= ' Granted: '.implode(', ', $added).'.';
        }

        if ($removed !== []) {
            $summary .= ' Revoked: '.implode(', ', $removed).'.';
        }

        if ($added === [] && $removed === []) {
            $summary .= ' Nothing changed.';
        }

        return back()->with('success', $summary);
    }
}
