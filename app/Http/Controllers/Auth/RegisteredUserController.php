<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminUserActivityNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): View
    {
        $queryCode = strtoupper(trim((string) $request->query('ref', '')));
        if ($queryCode !== '') {
            $request->session()->put('referral_code', $queryCode);
        }

        $referralCode = strtoupper(trim((string) old('ref', (string) $request->session()->get('referral_code', ''))));

        return view('auth.register', [
            'referralCode' => $referralCode,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'ref' => ['nullable', 'string', 'max:32'],
        ]);

        $firstName = trim((string) $validated['first_name']);
        $lastName = trim((string) $validated['last_name']);

        $attributes = [
            'name' => trim($firstName.' '.$lastName),
            'email' => $validated['email'],
            'password' => Hash::make((string) $validated['password']),
        ];

        if (Schema::hasColumn('users', 'first_name')) {
            $attributes['first_name'] = $firstName;
        }

        if (Schema::hasColumn('users', 'last_name')) {
            $attributes['last_name'] = $lastName;
        }

        if (Schema::hasColumn('users', 'referral_code')) {
            $attributes['referral_code'] = $this->generateUniqueReferralCode();
        }

        $incomingReferralCode = strtoupper(trim((string) ($validated['ref'] ?? $request->session()->get('referral_code', ''))));
        if ($incomingReferralCode !== '' && Schema::hasColumn('users', 'referred_by_user_id')) {
            $referrer = User::query()
                ->where('referral_code', $incomingReferralCode)
                ->first();

            if ($referrer) {
                $attributes['referred_by_user_id'] = (int) $referrer->id;
            }
        }

        $user = User::create($attributes);
        $request->session()->forget('referral_code');

        $this->notifyAdminsOfActivity('registration', $user, $request);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    private function generateUniqueReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
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
