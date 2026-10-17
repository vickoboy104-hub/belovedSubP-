<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the admin audit trail. One call per action, from inside the same
 * database transaction as the action itself, so a rolled-back change never
 * leaves a log entry claiming it happened, and a recorded success always
 * describes something that persisted.
 *
 * Three outcomes are distinguished on purpose. `success` is what happened.
 * `failed` is an action that was allowed but did not go through. `denied` is an
 * action this account is not permitted to take at all - and a denial is a finding
 * worth more than most successes, so it is recorded and never allowed to change
 * the response the visitor gets.
 *
 * Nothing that belongs in a credential or an identity number may pass through
 * here. Values are dropped by key name (passwords, tokens, secrets, signatures,
 * cookies) and digit runs of nine or more are masked to their last four, which
 * covers NINs, BVNs, card numbers and virtual account numbers without touching
 * this site's own alphanumeric references. A caller that wants a value recorded
 * should record the field name and the fact it changed, not the value.
 */
class AdminActivityRecorder
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function success(string $category, string $action, string $description, array $context = []): void
    {
        $this->record('success', $category, $action, $description, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function failed(string $category, string $action, string $description, array $context = []): void
    {
        $this->record('failed', $category, $action, $description, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denied(string $category, string $action, string $description, array $context = []): void
    {
        $this->record('denied', $category, $action, $description, $context);
    }

    /**
     * Recording must never break the thing it is describing. This variant is for
     * observations that sit outside a money path - a page view, a refusal, an
     * auth event - where losing one line is better than a 500 in front of a
     * customer. Anything that moved a balance uses the throwing form instead.
     *
     * @param array<string, mixed> $context
     */
    public function recordQuietly(string $result, string $category, string $action, string $description, array $context = []): void
    {
        try {
            $this->record($result, $category, $action, $description, $context);
        } catch (Throwable $e) {
            Log::warning('Admin activity record could not be written.', [
                'action' => $action,
                'result' => $result,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(string $result, string $category, string $action, string $description, array $context = []): void
    {
        if (! in_array($category, AdminActivityLog::CATEGORIES, true)) {
            $category = 'platform';
        }

        if (! in_array($result, AdminActivityLog::RESULTS, true)) {
            $result = 'failed';
        }

        // The signed-in account unless the caller names someone else - a logout
        // event, for instance, where the session is already gone by the time the
        // listener runs.
        $actor = $context['actor'] ?? auth()->user();
        [$targetType, $targetId] = $this->target($context['target'] ?? null);
        $correlationId = $this->correlationId();

        // A probing bot or a crawler that finds an admin URL would otherwise write
        // a row per hit, so repeated refusals of the same kind from the same
        // address are recorded once per window. The window is a recording rule,
        // not a judgement: the attempts still happened.
        $seconds = (int) ($context['throttle_seconds'] ?? 0);

        if ($seconds > 0 && ! Cache::add('audit:throttle:'.$action.':'.$result.':'.$this->ip(), 1, $seconds)) {
            return;
        }

        $row = [
            'correlation_id' => $correlationId,
            'admin_user_id' => $actor instanceof Authenticatable ? $actor->getAuthIdentifier() : null,
            'admin_name' => $this->actorName($actor),
            'category' => $category,
            'action' => Str::limit($action, 100, ''),
            'description' => Str::limit($this->mask($description), 1000, ''),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reference' => $this->reference($context),
            'result' => $result,
            'reason' => isset($context['reason']) ? Str::limit($this->mask((string) $context['reason']), 500, '') : null,
            'changes' => json_encode(
                $this->sanitize((array) ($context['changes'] ?? [])),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'ip_address' => $this->ip(),
            'session_hash' => $this->sessionHash(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 255, ''),
            'created_at' => now(),
            // The same request, doing the same thing to the same record and
            // ending the same way, is one event. A retry cannot stack it up.
            'dedupe_key' => substr(hash('sha256', implode('|', [
                $correlationId, $action, $result, $targetType, $targetId, $this->reference($context),
            ])), 0, 64),
        ];

        // Not AdminActivityLog::create(): the unique dedupe key must swallow a
        // duplicate rather than throw, and an insert statement is the one write
        // this table takes.
        DB::table('admin_activity_logs')->insertOrIgnore($row);
    }

    /**
     * A wallet reference is the useful thing to search on, and it is always this
     * site's own. Gateway payloads are never copied in.
     *
     * @param array<string, mixed> $context
     */
    private function reference(array $context): ?string
    {
        if (! isset($context['reference'])) {
            return null;
        }

        $value = trim((string) $context['reference']);

        return $value === '' ? null : Str::limit($value, 191, '');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function target(mixed $target): array
    {
        if (! is_array($target) || $target === []) {
            return [null, null];
        }

        $type = (string) ($target[0] ?? ($target['type'] ?? ''));
        $id = $target[1] ?? ($target['id'] ?? null);

        return [
            $type === '' ? null : Str::limit($type, 64, ''),
            $id === null ? null : Str::limit((string) $id, 64, ''),
        ];
    }

    private function actorName(mixed $actor): ?string
    {
        if ($actor instanceof Authenticatable) {
            $name = trim((string) ($actor->name ?? ''));

            return $name === '' ? ('Account #'.$actor->getAuthIdentifier()) : Str::limit($this->mask($name), 255, '');
        }

        if (is_string($actor) && trim($actor) !== '') {
            return Str::limit($this->mask(trim($actor)), 255, '');
        }

        // A denied attempt from somebody who is not signed in has no name to
        // give it, and inventing one would put words in an unknown visitor's mouth.
        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['_depth' => 'truncated'];
        }

        $clean = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if ($count++ >= 40) {
                $clean['_truncated'] = true;
                break;
            }

            if ($this->isSecretKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value, $depth + 1);
                continue;
            }

            if (is_object($value)) {
                $clean[$key] = $this->mask((string) $value);
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = Str::limit($this->mask($value), 300, '…');
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isSecretKey(string $key): bool
    {
        return (bool) preg_match(
            '/password|passwd|secret|token|authorization|cookie|api[_-]?key|apikey|private[_-]?key|signature|(_|^)pin($|_)|otp|cvv|cvc|session|remember|hash|payload/i',
            $key
        );
    }

    /**
     * Nine or more digits in a row is the shape of a NIN, a BVN, a card number or
     * a bank account. This site's own references carry letters, so they survive
     * intact and stay searchable.
     *
     * Groups are allowed between the digits, because that is how the sensitive
     * numbers are actually written: a card arrives as "5555 5555 5555 4444" and a
     * phone as "+234 801 234 5678". A rule that only looked at unbroken runs would
     * store those two verbatim while masking the NIN, which is the opposite of what
     * protection is for.
     */
    private function mask(string $value): string
    {
        return (string) preg_replace_callback(
            '/\d(?:[ \-]?\d){8,}/',
            static function (array $match): string {
                $digits = (string) preg_replace('/\D/', '', $match[0]);

                return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
            },
            $value
        );
    }

    private function correlationId(): string
    {
        $existing = $this->request->attributes->get('correlation_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $created = (string) Str::uuid();
        $this->request->attributes->set('correlation_id', $created);

        return $created;
    }

    private function ip(): ?string
    {
        $ip = $this->request->ip();

        return $ip === null ? null : Str::limit($ip, 45, '');
    }

    /**
     * Enough to group one sitting's actions, not enough to ride the session.
     * Keyed by the app secret, so a hash in this table cannot be reversed into
     * a session id even if the table leaks.
     */
    private function sessionHash(): ?string
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $id = (string) $this->request->session()->getId();

        if ($id === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 32);
    }
}
