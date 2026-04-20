<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportChatMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly SupportTicketMessage $message,
        private readonly bool $fromAdmin = false,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        if (Schema::hasTable('notifications')) {
            $channels[] = 'database';
        }

        $mailer = (string) config('mail.default', 'log');
        if (!in_array($mailer, ['log', 'array'], true)) {
            $channels[] = 'mail';
        }

        return empty($channels) ? ['mail'] : $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjectPrefix = $this->fromAdmin ? 'Admin Reply' : 'User Reply';

        return (new MailMessage)
            ->subject($subjectPrefix . ' on Support Ticket #' . $this->ticket->id)
            ->line('Ticket: #' . $this->ticket->id)
            ->line('Category: ' . $this->ticket->category)
            ->line('Message: ' . Str::limit($this->message->message, 200))
            ->action('Open Support Chat', url($this->fromAdmin ? '/support/bot' : '/admin/support/chats'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->fromAdmin ? 'Support Reply' : 'New User Chat Message',
            'message' => Str::limit($this->message->message, 140),
            'ticket_id' => $this->ticket->id,
            'url' => url($this->fromAdmin ? '/support/bot' : '/admin/support/chats'),
            'created_at_iso' => now()->toISOString(),
        ];
    }
}

