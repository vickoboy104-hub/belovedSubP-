<?php

namespace App\Http\Middleware;

use App\Services\AdminActivityRecorder;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops an administrator who does not hold the named permission, whatever they type
 * in the browser.
 *
 * The screen is hidden from them as a courtesy; this is the part that decides. A
 * person without wallet.credit cannot reach the credit form by URL, cannot replay a
 * saved post request, and cannot reach it from a link somebody else sent them,
 * because the check runs on the server before the controller is ever called.
 *
 * The refusal is written to the audit log: a permission that is never used is a
 * tidy desk, but a permission that is repeatedly reached for is a question the owner
 * should see.
 */
class EnsureAdminPermission
{
    public function __construct(
        private readonly AdminActivityRecorder $recorder,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($permissions === []) {
            // A route that names no permission is a route nobody wrote on purpose.
            abort(403, 'Unknown administrator permission.');
        }

        foreach ($permissions as $permission) {
            if (!AdminPermissions::exists($permission) && $permission !== AdminPermissions::MASTER_PERMISSION) {
                // A typo in a route file must not become an open door.
                abort(403, 'Unknown administrator permission.');
            }
        }

        if ($user === null || !$user->is_admin) {
            $this->recorder->recordQuietly('denied', 'auth', 'admin.access_denied', 'Attempted to reach an administrator screen.', [
                'target' => ['route', (string) $request->route()?->getName()],
                'reason' => $user === null ? 'Not signed in.' : 'This account is not an administrator.',
                'throttle_seconds' => 300,
            ]);

            abort(403, 'Unauthorized');
        }

        $held = $user->adminPermissionSlugs();

        // A screen that covers several kinds of work is opened by any one of them:
        // the settings page is one page, but prices, provider routing and site
        // behaviour are three separate grants, and a person who holds only prices
        // still has to be able to reach the page to do that job.
        if (array_intersect($permissions, $held) !== [] || $user->isMasterAdmin()) {
            return $next($request);
        }

        $this->recorder->recordQuietly('denied', 'auth', 'admin.permission_denied', 'Attempted an action this account is not permitted to perform.', [
            'target' => ['route', (string) $request->route()?->getName()],
            'reason' => 'Missing permission: '.implode(' or ', $permissions).'.',
            'changes' => ['permissions_required' => $permissions],
            'throttle_seconds' => 300,
        ]);

        abort(403, 'You do not have permission to do that.');
    }
}
