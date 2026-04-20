<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Notifications\AdminUserActivityNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): View
    {
        $request->session()->regenerateToken();
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        if ($user) {
            if (Schema::hasColumn('users', 'last_login_at') && Schema::hasColumn('users', 'last_login_ip')) {
                try {
                    $user->last_login_at = now();
                    $user->last_login_ip = $request->ip();
                    $user->save();
                } catch (\Throwable $e) {
                    Log::warning('Failed to persist login metadata.', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning('Skipped login metadata update: users.last_login_at/last_login_ip columns are missing.');
            }

            $this->notifyAdminsOfActivity('login', $user, $request);
        }

        return redirect()->route('dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function notifyAdminsOfActivity(string $activity, User $actor, Request $request): void
    {
        try {
            $admins = User::query()->where('is_admin', true)->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::send(
                $admins,
                new AdminUserActivityNotification(
                    activity: $activity,
                    userId: (int) $actor->id,
                    userName: (string) ($actor->name ?: trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''))),
                    userEmail: (string) $actor->email,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about user activity.', [
                'activity' => $activity,
                'user_id' => $actor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
