<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

class AdminSystemAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $severity = 'info',
        private readonly ?string $url = null,
        private readonly array $payload = [],
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

        if ($channels === []) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Admin Alert')
            ->line($this->message)
            ->line('Severity: '.strtoupper($this->severity))
            ->line('Time: '.now()->format('d M Y, h:ia'));

        if ($this->severity === 'critical') {
            $mail->error();
        }

        if ($this->url) {
            $mail->action('Open Admin Panel', $this->url);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'url' => $this->url ?: url('/admin'),
            'created_at_iso' => now()->toISOString(),
        ], $this->payload);
    }
}
