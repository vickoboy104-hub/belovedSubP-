<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminBroadcastNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BroadcastController extends Controller
{
    /**
     * Shared hosting kills a request long before a whole user base can be
     * messaged in one pass, so the page walks the table a slice at a time.
     */
    private const BATCH_SIZE = 25;

    public function index(): View
    {
        return view('admin.broadcast', [
            'totalUsers' => User::query()->count(),
            'usersWithEmail' => User::query()->whereNotNull('email')->where('email', '!=', '')->count(),
            'usersWithPhone' => User::query()->whereNotNull('phone')->where('phone', '!=', '')->count(),
            'mailMailer' => (string) config('mail.default', 'log'),
            'mailReady' => $this->mailReady(),
            'recentBroadcasts' => $this->recentBroadcasts(),
            'batchSize' => self::BATCH_SIZE,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'channels' => ['required', 'array'],
            'channels.*' => ['in:database,mail'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $channels = array_values(array_unique($data['channels']));

        if ($channels === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Pick at least one delivery channel.',
            ], 422);
        }

        // A requested mail channel the server cannot honour is dropped rather
        // than silently written to laravel.log and reported as delivered.
        $wantedMail = in_array('mail', $channels, true);
        if ($wantedMail && !$this->mailReady()) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        $after = (int) ($data['after'] ?? 0);
        $recipients = User::query()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send(
                $recipients,
                new AdminBroadcastNotification($data['title'], $data['message'], $channels)
            );
        }

        $lastId = (int) ($recipients->last()?->id ?? $after);

        return response()->json([
            'ok' => true,
            'sent' => $recipients->count(),
            'emails' => in_array('mail', $channels, true)
                ? $recipients->filter(fn (User $user) => filled($user->email))->count()
                : 0,
            'mailSkipped' => $wantedMail && !$this->mailReady(),
            'after' => $lastId,
            'has_more' => User::query()->where('id', '>', $lastId)->exists(),
            'remaining' => User::query()->where('id', '>', $lastId)->count(),
        ]);
    }

    /**
     * Every SMS panel wants the same two things: E.164 numbers and the message
     * body. No gateway is wired into this app, so the list is handed over ready
     * to paste or upload.
     */
    public function export(): StreamedResponse
    {
        $filename = 'broadcast-recipients-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['name', 'phone', 'e164']);

            User::query()
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->orderBy('id')
                ->chunk(200, function ($users) use ($handle) {
                    foreach ($users as $user) {
                        fputcsv($handle, [
                            $user->name,
                            $user->phone,
                            $this->toE164((string) $user->phone),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function mailReady(): bool
    {
        return !in_array((string) config('mail.default', 'log'), ['log', 'array'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentBroadcasts(): array
    {
        return DatabaseNotification::query()
            ->where('type', AdminBroadcastNotification::class)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            // One row per recipient, so collapse a run of identical messages
            // sent in the same minute back into the campaign the admin sees.
            ->groupBy(fn (DatabaseNotification $note) => ($note->data['title'] ?? '').'|'.($note->data['message'] ?? '').'|'.$note->created_at?->format('Y-m-d H:i'))
            ->map(fn ($group) => [
                'title' => $group->first()->data['title'] ?? 'Announcement',
                'message' => $group->first()->data['message'] ?? '',
                'recipients' => $group->count(),
                'sent_at' => $group->max('created_at'),
            ])
            ->values()
            ->take(8)
            ->all();
    }

    private function toE164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00234')) {
            $digits = '234'.substr($digits, 5);
        } elseif (str_starts_with($digits, '234')) {
            // Already country-coded.
        } elseif (str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '234'.$digits;
        }

        return '+'.$digits;
    }
}
