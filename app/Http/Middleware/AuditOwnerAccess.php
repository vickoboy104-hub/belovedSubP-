<?php

namespace App\Http\Middleware;

use App\Services\AdminActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the screens that show what every admin did.
 *
 * `is_admin` is not enough here: any admin could otherwise read - and, if the
 * model let them, erase - the record of the others. The allow-list is
 * config/audit.php, which is filled only from the owner's own statement of which
 * account is theirs. It ships empty, so until that is set nobody sees the trail,
 * this middleware's author included. An unassigned privilege is the safe failure;
 * a guessed one is not.
 */
class AuditOwnerAccess
{
    public function __construct(
        private readonly AdminActivityRecorder $recorder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isOwner($request)) {
            $this->recorder->recordQuietly('denied', 'auth', 'audit.access_denied', 'Attempted to open the administrator audit screens.', [
                'target' => ['route', $request->route()?->getName()],
                'reason' => 'The account is not named in the audit owner list.',
                'throttle_seconds' => 300,
            ]);

            abort(403, 'This area belongs to the account owner.');
        }

        return $next($request);
    }

    public static function isOwner(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && in_array((int) $user->id, array_map('intval', (array) config('audit.owner_user_ids')), true);
    }
}
