<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserWalletActivityNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly array $payload = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $mailer = (string) config('mail.default', 'log');
        if (!in_array($mailer, ['log', 'array'], true) && (($this->payload['send_mail'] ?? false) === true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Wallet Update')
            ->line($this->message)
            ->line('Time: ' . now()->format('d M Y, h:ia'))
            ->action('Open Wallet Transactions', url('/wallet/transactions'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'created_at_iso' => now()->toISOString(),
        ], $this->payload);
    }
}
