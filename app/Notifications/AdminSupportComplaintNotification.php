<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminSupportComplaintNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly ?User $reporter = null,
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

        if (empty($channels)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reporter = $this->reporter?->email ?: 'Guest';
        $category = $this->ticket->category;
        $subject = $this->ticket->subject ?: 'No subject';

        $mail = (new MailMessage)
            ->subject('New Support Complaint: ' . $category)
            ->greeting('Admin Alert')
            ->line('A new complaint was submitted via chatbot/support form.')
            ->line('Category: ' . $category)
            ->line('Subject: ' . $subject)
            ->line('Submitted by: ' . $reporter)
            ->line('Message: ' . $this->ticket->message)
            ->action('Open Admin Dashboard', url('/admin'));

        if (!empty($this->ticket->attachment_path)) {
            $mail->line('Attachment: ' . url('/storage/' . ltrim($this->ticket->attachment_path, '/')));
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New Support Complaint',
            'message' => 'Category: ' . $this->ticket->category . ' | ' . Str::limit($this->ticket->message, 120),
            'ticket_id' => $this->ticket->id,
            'category' => $this->ticket->category,
            'subject' => $this->ticket->subject,
            'user_id' => $this->reporter?->id,
            'user_email' => $this->reporter?->email,
            'attachment_path' => $this->ticket->attachment_path,
            'url' => url('/admin'),
            'created_at_iso' => now()->toISOString(),
        ];
    }
}
