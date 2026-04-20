<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\AdminSupportComplaintNotification;
use App\Notifications\SupportChatMessageNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class SupportBotController extends Controller
{
    public function index(): View
    {
        $issueOptions = [
            'wallet_funding' => 'Wallet funding issue',
            'failed_transaction' => 'Failed transaction',
            'delayed_delivery' => 'Delayed delivery',
            'account_access' => 'Login/account access',
            'api_or_service' => 'Service/API issue',
            'other' => 'Other complaint',
        ];

        $tickets = collect();
        $setupError = null;
        try {
            $tickets = SupportTicket::query()
                ->where('user_id', auth()->id())
                ->latest()
                ->take(10)
                ->get();
        } catch (QueryException $e) {
            Log::error('Support bot index query error', ['error' => $e->getMessage()]);
            $setupError = 'Support system setup is incomplete. Please run database migrations.';
        }

        return view('support.bot', compact('issueOptions', 'tickets', 'setupError'));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $issueOptions = [
            'wallet_funding',
            'failed_transaction',
            'delayed_delivery',
            'account_access',
            'api_or_service',
            'other',
        ];

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', $issueOptions)],
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('support', 'public');
        }

        try {
            $ticket = SupportTicket::create([
                'user_id' => auth()->id(),
                'category' => (string) $validated['category'],
                'subject' => trim((string) ($validated['subject'] ?? '')) ?: null,
                'message' => (string) $validated['message'],
                'attachment_path' => $attachmentPath,
                'status' => 'open',
                'meta' => [
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
                'user_last_read_at' => now(),
                'last_message_at' => now(),
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'is_admin' => false,
                'message' => (string) $validated['message'],
                'attachment_path' => $attachmentPath,
                'meta' => [
                    'created_via' => 'support_form',
                ],
            ]);

            $admins = User::query()->where('is_admin', true)->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AdminSupportComplaintNotification($ticket, $request->user()));
            }
        } catch (QueryException $e) {
            Log::error('Support bot query error', ['error' => $e->getMessage()]);
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Support system setup is incomplete. Please run database migrations and try again.',
                ], 500);
            }
            return back()->with('error', 'Support system setup is incomplete. Please run database migrations and try again.');
        } catch (\Throwable $e) {
            Log::error('Support bot failed', ['error' => $e->getMessage()]);
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unable to submit complaint right now. Please try again.',
                ], 500);
            }
            return back()->with('error', 'Unable to submit complaint right now. Please try again.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Complaint submitted. Admin has been notified.',
                'ticket_id' => $ticket->id,
            ]);
        }

        return back()->with('success', 'Complaint submitted. Admin has been notified.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = (bool) ($user?->is_admin ?? false);

        try {
            $query = SupportTicket::query()
                ->with(['user:id,name,email', 'latestMessage'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('updated_at');

            if (!$isAdmin) {
                $query->where('user_id', $user->id);
            }

            $tickets = $query->take($isAdmin ? 100 : 30)->get()->map(function (SupportTicket $ticket) use ($isAdmin) {
                $lastMessage = $ticket->latestMessage;
                $cutoff = $isAdmin ? $ticket->admin_last_read_at : $ticket->user_last_read_at;

                $unreadCount = SupportTicketMessage::query()
                    ->where('support_ticket_id', $ticket->id)
                    ->where('is_admin', !$isAdmin)
                    ->when($cutoff, fn ($q) => $q->where('created_at', '>', $cutoff))
                    ->count();

                return [
                    'id' => $ticket->id,
                    'category' => $ticket->category,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'user' => [
                        'id' => $ticket->user?->id,
                        'name' => $ticket->user?->name,
                        'email' => $ticket->user?->email,
                    ],
                    'last_message' => $lastMessage?->message,
                    'last_message_at' => optional($ticket->last_message_at ?: $lastMessage?->created_at)?->toISOString(),
                    'unread_count' => $unreadCount,
                ];
            });

            return response()->json([
                'ok' => true,
                'tickets' => $tickets,
            ]);
        } catch (\Throwable $e) {
            Log::error('Support sessions load failed', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Unable to load support sessions.',
                'tickets' => [],
            ], 500);
        }
    }

    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (!$this->canAccessTicket($user?->id, (bool) ($user?->is_admin ?? false), $ticket)) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $isAdmin = (bool) ($user?->is_admin ?? false);
        if ($isAdmin) {
            $ticket->admin_last_read_at = now();
        } else {
            $ticket->user_last_read_at = now();
        }
        $ticket->save();

        $messages = $ticket->messages()
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->take(200)
            ->get()
            ->map(function (SupportTicketMessage $msg) use ($user) {
                return [
                    'id' => $msg->id,
                    'message' => $msg->message,
                    'is_admin' => (bool) $msg->is_admin,
                    'self' => (int) ($msg->user_id ?? 0) === (int) ($user?->id ?? 0),
                    'user_name' => $msg->user?->name ?? ($msg->is_admin ? 'Admin' : 'User'),
                    'attachment_url' => $msg->attachment_path ? asset('storage/' . ltrim($msg->attachment_path, '/')) : null,
                    'created_at' => optional($msg->created_at)->toISOString(),
                ];
            });

        return response()->json([
            'ok' => true,
            'ticket' => [
                'id' => $ticket->id,
                'status' => $ticket->status,
                'category' => $ticket->category,
                'subject' => $ticket->subject,
            ],
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $isAdmin = (bool) ($user?->is_admin ?? false);

        if (!$this->canAccessTicket($user?->id, $isAdmin, $ticket)) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:5120'],
            'status' => ['nullable', 'string', 'in:open,pending,resolved,closed'],
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('support', 'public');
        }

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'is_admin' => $isAdmin,
            'message' => (string) $validated['message'],
            'attachment_path' => $attachmentPath,
            'meta' => [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        $ticket->last_message_at = now();
        if ($isAdmin) {
            $ticket->admin_last_read_at = now();
            if (isset($validated['status'])) {
                $ticket->status = (string) $validated['status'];
            }
        } else {
            $ticket->user_last_read_at = now();
            if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
                $ticket->status = 'open';
            }
        }
        $ticket->save();

        if ($isAdmin) {
            if ($ticket->user) {
                $ticket->user->notify(new SupportChatMessageNotification($ticket, $message, true));
            }
        } else {
            $admins = User::query()->where('is_admin', true)->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new SupportChatMessageNotification($ticket, $message, false));
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Message sent.',
            'data' => [
                'id' => $message->id,
                'message' => $message->message,
                'is_admin' => (bool) $message->is_admin,
                'self' => true,
                'user_name' => $user?->name ?? ($isAdmin ? 'Admin' : 'User'),
                'attachment_url' => $message->attachment_path ? asset('storage/' . ltrim($message->attachment_path, '/')) : null,
                'created_at' => optional($message->created_at)->toISOString(),
            ],
        ]);
    }

    private function canAccessTicket(?int $userId, bool $isAdmin, SupportTicket $ticket): bool
    {
        if ($isAdmin) return true;
        return (int) ($ticket->user_id ?? 0) === (int) ($userId ?? 0);
    }
}
