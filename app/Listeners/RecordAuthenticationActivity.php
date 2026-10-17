<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AdminActivityRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Puts sign-in and sign-out into the audit trail.
 *
 * Scope is deliberate. This is an administrator activity log, not a visitor
 * counter, so a successful entry is recorded only for accounts that can reach the
 * admin screens - recording every customer login would bury the evidence in
 * routine traffic. A refused entry says nothing about who was typed in: the
 * address someone guessed at is personal data belonging to a third party, and an
 * IP, a time and a user agent expose a guessing attempt just as well.
 */
class RecordAuthenticationActivity
{
    public function __construct(
        private readonly AdminActivityRecorder $recorder,
    ) {
    }

    public function onLogin(Login $event): void
    {
        if (! $this->isStaff($event->user)) {
            return;
        }

        $this->recorder->recordQuietly('success', 'auth', 'auth.login', 'Signed in to the administrator area.', [
            'actor' => $event->user,
            'target' => ['user', $event->user->id ?? null],
        ]);
    }

    public function onLogout(Logout $event): void
    {
        if (! $this->isStaff($event->user)) {
            return;
        }

        $this->recorder->recordQuietly('success', 'auth', 'auth.logout', 'Signed out of the administrator area.', [
            'actor' => $event->user,
            'target' => ['user', $event->user->id ?? null],
        ]);
    }

    public function onFailed(Failed $event): void
    {
        // $event->credentials holds what was typed, including the password. It is
        // never read here, and nothing derived from it is stored.
        $guard = (string) ($event->guard ?? 'web');

        $this->recorder->recordQuietly('denied', 'auth', 'auth.login_failed', 'Sign-in attempt was refused.', [
            'target' => ['guard', $guard],
            'reason' => 'The credentials did not match an account.',
            'throttle_seconds' => 60,
        ]);
    }

    public function onLockout(Lockout $event): void
    {
        $this->recorder->recordQuietly('denied', 'auth', 'auth.login_throttled', 'Sign-in attempts from this address were rate limited.', [
            'reason' => 'Too many attempts.',
            'throttle_seconds' => 300,
        ]);
    }

    private function isStaff(mixed $user): bool
    {
        return $user instanceof User && (bool) $user->is_admin
            && $user->id !== null;
    }
}
