<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AdminActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportChatsController extends Controller
{
    public function index(Request $request, AdminActivityRecorder $audit): View
    {
        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->take(40)
            ->get();

        // Support threads carry whatever a customer typed in anger or panic, so the
        // trail notes that the inbox was opened, not what was read in it. Repeated
        // opens inside five minutes are one line, not a log of a working session.
        $audit->recordQuietly('success', 'support', 'support.inbox_opened', 'Opened the customer support inbox.', [
            'changes' => ['threads' => $tickets->count()],
            'throttle_seconds' => 300,
        ]);

        return view('admin.support-chats', [
            'tickets' => $tickets,
        ]);
    }
}

