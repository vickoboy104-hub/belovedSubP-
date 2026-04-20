<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportChatsController extends Controller
{
    public function index(Request $request): View
    {
        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->take(40)
            ->get();

        return view('admin.support-chats', [
            'tickets' => $tickets,
        ]);
    }
}

